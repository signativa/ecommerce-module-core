<?php

namespace Mundipagg\Core\Kernel\Services;

use Mundipagg\Core\Kernel\Abstractions\AbstractDataService;
use Mundipagg\Core\Kernel\Aggregates\Order;
use Mundipagg\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Mundipagg\Core\Kernel\Exceptions\InvalidParamException;
use Mundipagg\Core\Kernel\Interfaces\PlatformOrderInterface;
use Mundipagg\Core\Kernel\Repositories\OrderRepository;
use Mundipagg\Core\Kernel\ValueObjects\Id\OrderId;
use Mundipagg\Core\Kernel\ValueObjects\OrderState;
use Mundipagg\Core\Kernel\ValueObjects\OrderStatus;
use Mundipagg\Core\Kernel\ValueObjects\TransactionStatus;
use Mundipagg\Core\Kernel\ValueObjects\TransactionStatusError;
use Mundipagg\Core\Payment\Aggregates\Customer;
use Mundipagg\Core\Payment\Interfaces\ResponseHandlerInterface;
use Mundipagg\Core\Payment\Services\ResponseHandlerExceptions\DefaultExceptionHandler;
use Mundipagg\Core\Payment\Services\ResponseHandlerExceptions\TransactionExceptionHandler;
use Mundipagg\Core\Payment\Services\ResponseHandlers\ErrorExceptionHandler;
use Mundipagg\Core\Payment\ValueObjects\CustomerType;
use Mundipagg\Core\Kernel\Factories\OrderFactory;
use Mundipagg\Core\Kernel\Factories\ChargeFactory;
use Mundipagg\Core\Payment\Aggregates\Order as PaymentOrder;
use Exception;

final class OrderService
{
    private $logService;

    /**
     * @var OrderRepository
     */
    private $orderRepository;

    public function __construct()
    {
        $this->logService = new OrderLogService();
        $this->orderRepository = new OrderRepository();
    }

    /**
     *
     * @param Order $order
     * @param bool $changeStatus
     */
    public function syncPlatformWith(Order $order, $changeStatus = true)
    {
        $moneyService = new MoneyService();

        $paidAmount = 0;
        $canceledAmount = 0;
        $refundedAmount = 0;
        foreach ($order->getCharges() as $charge) {
            $paidAmount += $charge->getPaidAmount();
            $canceledAmount += $charge->getCanceledAmount();
            $refundedAmount += $charge->getRefundedAmount();
        }

        $paidAmount = $moneyService->centsToFloat($paidAmount);
        $canceledAmount = $moneyService->centsToFloat($canceledAmount);
        $refundedAmount = $moneyService->centsToFloat($refundedAmount);

        $platformOrder = $order->getPlatformOrder();

        $platformOrder->setTotalPaid($paidAmount);
        $platformOrder->setBaseTotalPaid($paidAmount);
        $platformOrder->setTotalCanceled($canceledAmount);
        $platformOrder->setBaseTotalCanceled($canceledAmount);
        $platformOrder->setTotalRefunded($refundedAmount);
        $platformOrder->setBaseTotalRefunded($refundedAmount);

        if ($changeStatus) {
            $this->changeOrderStatus($order);
        }

        $platformOrder->save();
    }

    public function changeOrderStatus(Order $order)
    {
        $platformOrder = $order->getPlatformOrder();
        $orderStatus = $order->getStatus();
        if ($orderStatus->equals(OrderStatus::paid())) {
            $orderStatus = OrderStatus::processing();
        }

        //@todo In the future create a core status machine with the platform
        if (!$order->getPlatformOrder()->getState()->equals(OrderState::closed())) {
            $platformOrder->setStatus($orderStatus);
        }
    }
    public function updateAcquirerData(Order $order)
    {
        $dataServiceClass =
            MPSetup::get(MPSetup::CONCRETE_DATA_SERVICE);

        /**
         *
         * @var AbstractDataService $dataService
         */
        $dataService = new $dataServiceClass();

        $dataService->updateAcquirerData($order);
    }

    public function cancelAtMundipagg(Order $order)
    {
        $orderRepository = new OrderRepository();
        $savedOrder = $orderRepository->findByMundipaggId($order->getMundipaggId());
        if ($savedOrder !== null) {
            $order = $savedOrder;
        }

        if ($order->getStatus()->equals(OrderStatus::canceled())) {
            return;
        }

        $APIService = new APIService();

        $charges = $order->getCharges();
        $results = [];
        foreach ($charges as $charge) {
            $result = $APIService->cancelCharge($charge);
            if ($result !== null) {
                $results[$charge->getMundipaggId()->getValue()] = $result;
            }
            $order->updateCharge($charge);
        }

        $i18n = new LocalizationService();

        if (empty($results)) {
            $order->setStatus(OrderStatus::canceled());
            $order->getPlatformOrder()->setStatus(OrderStatus::canceled());

            $orderRepository->save($order);
            $order->getPlatformOrder()->save();

            $statusOrderLabel = $order->getPlatformOrder()->getStatusLabel(
                $order->getStatus()
            );

            $messageComplementEmail = $i18n->getDashboard(
                'New order status: %s',
                $statusOrderLabel
            );

            $sender = $order->getPlatformOrder()->sendEmail($messageComplementEmail);

            $order->getPlatformOrder()->addHistoryComment(
                $i18n->getDashboard(
                    "Order '%s' canceled at Mundipagg",
                    $order->getMundipaggId()->getValue()
                ),
                $sender
            );

            return;
        }

        $history = $i18n->getDashboard("Some charges couldn't be canceled at Mundipagg. Reasons:");
        $history .= "<br /><ul>";
        foreach ($results as $chargeId => $reason)
        {
            $history .= "<li>$chargeId : $reason</li>";
        }
        $history .= '</ul>';
        $order->getPlatformOrder()->addHistoryComment($history);
        $order->getPlatformOrder()->save();
    }

    public function cancelAtMundipaggByPlatformOrder(PlatformOrderInterface $platformOrder)
    {
        $orderId = $platformOrder->getMundipaggId();
        if (empty($orderId)) {
            return;
        }

        $APIService = new APIService();

        $order = $APIService->getOrder($orderId);
        if (is_a($order, Order::class)) {
            $this->cancelAtMundipagg($order);
        }
    }

    /**
     * @param PlatformOrderInterface $platformOrder
     * @return array
     * @throws \Exception
     */
    public function createOrderAtMundipagg(PlatformOrderInterface $platformOrder)
    {
        try {
            $orderInfo = $this->getOrderInfo($platformOrder);

            $this->logService->orderInfo(
                $platformOrder->getCode(),
                'Creating order.',
                $orderInfo
            );
            //set pending
            $platformOrder->setState(OrderState::stateNew());
            $platformOrder->setStatus(OrderStatus::pending());

            //build PaymentOrder based on platformOrder
            $order =  $this->extractPaymentOrderFromPlatformOrder($platformOrder);

            $i18n = new LocalizationService();

            //Send through the APIService to mundipagg
            $apiService = new APIService();
            //$response = $apiService->createOrder($order);
            $response = json_decode('{"id":"or_EZygWgpCzckB53Ke","code":"15000000084","currency":"BRL","items":[{"id":"oi_e1grpg0UnfkRk8Mn","amount":10486,"description":"Jogo Tigela Copo Americano Com Tampa Pl\u00e1stica 350ml 12 pe\u00e7as : ","quantity":1,"GetSellerResponse":null,"category":null,"code":"219"}],"customer":{"id":"cus_LQx3EwVFJFXy5bBl","name":"Deogenes Nicoletti","email":"deogenes@signativa.com.br","delinquent":false,"created_at":"2021-02-19T20:00:54+00:00","updated_at":"2021-02-19T20:00:54+00:00","document":"56775268009","type":"individual","fb_access_token":null,"address":{"id":"addr_YvZVNadtlt6Ek5yW","street":"Rua S\u00e3o Jo\u00e3o","number":"297","complement":"","zip_code":"89052-300","neighborhood":"Itoupava Norte","city":"Blumenau","state":"SC","country":"BR","status":"active","created_at":"2021-02-19T20:00:54+00:00","updated_at":"2021-02-19T20:00:54+00:00","customer":null,"metadata":null,"line_1":"297,Rua S\u00e3o Jo\u00e3o,Itoupava Norte","line_2":"","deleted_at":null},"metadata":null,"phones":{"home_phone":{"country_code":"55","number":"996139508","area_code":"47"},"mobile_phone":{"country_code":"55","number":"996139508","area_code":"47"}},"fb_id":null,"code":"35","document_type":null},"status":"failed","created_at":"2021-02-23T22:13:12+00:00","updated_at":"2021-02-23T22:13:13+00:00","charges":[{"id":"ch_qoKM9Z5uewfNR0EA","code":"15000000084","gateway_id":"4376ccdc-5f18-46eb-b05b-5a66c8adec0e","amount":11530,"status":"failed","currency":"BRL","payment_method":"credit_card","due_at":null,"created_at":"2021-02-23T22:13:12+00:00","updated_at":"2021-02-23T22:13:13+00:00","last_transaction":{"statement_descriptor":"Nadir Figueiredo","acquirer_name":"cielo","acquirer_affiliation_code":"","acquirer_tid":"28004017310ULR9HJG2E","acquirer_nsu":"004004","acquirer_auth_code":null,"operation_type":"auth_and_capture","card":{"id":"card_3yBZd0ptokurZ9g7","last_four_digits":"7270","brand":"Mastercard","holder_name":"teste","exp_month":4,"exp_year":2023,"status":"active","created_at":"2021-02-23T22:11:03+00:00","updated_at":"2021-02-23T22:13:12+00:00","billing_address":null,"customer":null,"metadata":null,"type":"credit","holder_document":null,"deleted_at":null,"first_six_digits":"525709","label":null},"acquirer_message":"Cielo|Autorizacao negada","acquirer_return_code":"57","installments":2,"threed_authentication_url":null,"gateway_id":"60b27426-99d7-4e01-bc90-caadb2157e4a","amount":11530,"status":"not_authorized","success":false,"created_at":"2021-02-23T22:13:12+00:00","updated_at":"2021-02-23T22:13:12+00:00","attempt_count":null,"max_attempts":null,"splits":null,"next_attempt":null,"transaction_type":"credit_card","id":"tran_Nwl5eLcVZhay1zej","gateway_response":{"code":"201","errors":[]},"antifraud_response":{"status":null,"return_code":null,"return_message":null,"provider_name":null,"score":null},"metadata":{"saveOnSuccess":"false"},"split":null},"invoice":null,"order":null,"customer":{"id":"cus_LQx3EwVFJFXy5bBl","name":"Deogenes Nicoletti","email":"deogenes@signativa.com.br","delinquent":false,"created_at":"2021-02-19T20:00:54+00:00","updated_at":"2021-02-19T20:00:54+00:00","document":"56775268009","type":"individual","fb_access_token":null,"address":{"id":"addr_YvZVNadtlt6Ek5yW","street":"Rua S\u00e3o Jo\u00e3o","number":"297","complement":"","zip_code":"89052-300","neighborhood":"Itoupava Norte","city":"Blumenau","state":"SC","country":"BR","status":"active","created_at":"2021-02-19T20:00:54+00:00","updated_at":"2021-02-19T20:00:54+00:00","customer":null,"metadata":null,"line_1":"297,Rua S\u00e3o Jo\u00e3o,Itoupava Norte","line_2":"","deleted_at":null},"metadata":null,"phones":{"home_phone":{"country_code":"55","number":"996139508","area_code":"47"},"mobile_phone":{"country_code":"55","number":"996139508","area_code":"47"}},"fb_id":null,"code":"35","document_type":null},"metadata":{"moduleVersion":"1.8.19","coreVersion":"1.12.2","platformVersion":"Magento Community 2.3.4","saveOnSuccess":"false"},"paid_at":null,"canceled_at":null,"canceled_amount":null,"paid_amount":null}],"invoice_url":null,"shipping":{"amount":1044,"description":"Servi\u00e7os de Entrega - Standard - Em at\u00e9 10 dias \u00fateis","recipient_name":"Deogenes Nicoletti","recipient_phone":"5547996139508","address":{"id":null,"street":"Rua S\u00e3o Jo\u00e3o","number":"297","complement":"","zip_code":"89052-300","neighborhood":"Itoupava Norte","city":"Blumenau","state":"SC","country":"BR","status":null,"created_at":null,"updated_at":null,"customer":null,"metadata":null,"line_1":"297,Rua S\u00e3o Jo\u00e3o,Itoupava Norte","line_2":"","deleted_at":null},"max_delivery_date":null,"estimated_delivery_date":null,"type":null},"metadata":{"moduleVersion":"1.8.19","coreVersion":"1.12.2","platformVersion":"Magento Community 2.3.4"},"checkouts":[],"ip":null,"session_id":null,"location":null,"device":null,"closed":true}', true);

            $originalResponse = $response;
            $forceCreateOrder = MPSetup::getModuleConfiguration()->isCreateOrderEnabled();

            if (!$forceCreateOrder && !$this->checkResponseStatus($response)) {
                $this->logService->orderInfo(
                    $platformOrder->getCode(),
                    "Can't create order. - Force Create Order: {$forceCreateOrder} | Order or charge status failed",
                    $orderInfo
                );
                $this->persistListChargeFailed($response);

                $transactionExceptionHandler = new TransactionExceptionHandler();
                $defaultExceptionHandler = new DefaultExceptionHandler();

                $exceptionHandler = $transactionExceptionHandler
                    ->setNext($defaultExceptionHandler)
                    ->handle($response);

                $message = $i18n->getDashboard($exceptionHandler->getMessage());
                throw new \Exception($message, 400);
            }

            $platformOrder->save();

            $orderFactory = new OrderFactory();
            $response = $orderFactory->createFromPostData($response);

            $response->setPlatformOrder($platformOrder);

            $handler = $this->getResponseHandler($response);
            $handler->handle($response, $order);

            $platformOrder->save();

            if ($forceCreateOrder && !$this->checkResponseStatus($originalResponse)) {
                $this->logService->orderInfo(
                    $platformOrder->getCode(),
                    "Can't create order. - Force Create Order: {$forceCreateOrder} | Order or charge status failed",
                    $orderInfo
                );

                $message = $i18n->getDashboard("Can't create order.");
                throw new \Exception($message, 400);
            }

            return [$response];
        } catch (\Exception $e) {
            $this->logService->orderInfo(
                $platformOrder->getCode(),
                $e->getMessage(),
                $orderInfo
            );
            $exceptionHandler = new ErrorExceptionHandler();
            $paymentOrder = new PaymentOrder();
            $paymentOrder->setCode($platformOrder->getcode());
            $frontMessage = $exceptionHandler->handle($e, $paymentOrder);

            throw new \Exception($frontMessage, 400);
        }
    }

    /** @return ResponseHandlerInterface */
    private function getResponseHandler($response)
    {
        $responseClass = get_class($response);
        $responseClass = explode('\\', $responseClass);

        $responseClass =
            'Mundipagg\\Core\\Payment\\Services\\ResponseHandlers\\' .
            end($responseClass) . 'Handler';

        return new $responseClass;
    }

    public function extractPaymentOrderFromPlatformOrder(
        PlatformOrderInterface $platformOrder
    ) {
        $moduleConfig = MPSetup::getModuleConfiguration();

        $moneyService = new MoneyService();

        $user = new Customer();
        $user->setType(CustomerType::individual());

        $order = new PaymentOrder();

        $order->setAmount(
            $moneyService->floatToCents(
                $platformOrder->getGrandTotal()
            )
        );
        $order->setCustomer($platformOrder->getCustomer());
        $order->setAntifraudEnabled($moduleConfig->isAntifraudEnabled());
        $order->setPaymentMethod($platformOrder->getPaymentMethod());

        $payments = $platformOrder->getPaymentMethodCollection();
        foreach ($payments as $payment) {
            $order->addPayment($payment);
        }

        if (!$order->isPaymentSumCorrect()) {
            $message = 'The sum of payments is different than the order amount!';
            $this->logService->orderInfo(
                $platformOrder->getCode(),
                $message,
                $orderInfo
            );
            throw new \Exception($message,400);
        }

        $items = $platformOrder->getItemCollection();
        foreach ($items as $item) {
            $order->addItem($item);
        }

        $order->setCode($platformOrder->getCode());

        $shipping = $platformOrder->getShipping();
        if ($shipping !== null) {
            $order->setShipping($shipping);
        }

        return $order;
    }

    /**
     * @param PlatformOrderInterface $platformOrder
     * @return \stdClass
     */
    public function getOrderInfo(PlatformOrderInterface $platformOrder)
    {
        $orderInfo = new \stdClass();
        $orderInfo->grandTotal = $platformOrder->getGrandTotal();
        return $orderInfo;
    }

    /**
     * @param $response
     * @return boolean
     */
    private function checkResponseStatus($response)
    {
        if (
            !isset($response['status']) ||
            !isset($response['charges']) ||
            $response['status'] == 'failed'
        ) {
            return false;
        }

        foreach ($response['charges'] as $charge) {
            if (isset($charge['status']) && $charge['status'] == 'failed') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param $response
     * @throws InvalidParamException
     * @throws Exception
     */
    private function persistListChargeFailed($response)
    {
        if (empty($response['charges'])) {
            return;
        }

        $chargeFactory = new ChargeFactory();
        $chargeService = new ChargeService();

        foreach ($response['charges'] as $chargeResponse) {
            $order = ['order' => ['id' => $response['id']]];
            $charge = $chargeFactory->createFromPostData(
                array_merge($chargeResponse, $order)
            );

            $chargeService->save($charge);
        }
    }

    /**
     * @return Order|null
     * @throws InvalidParamException
     */
    public function getOrderByMundiPaggId(OrderId $orderId)
    {
        return $this->orderRepository->findByMundipaggId($orderId);
    }

    /**
     * @param string $platformOrderID
     * @return Order|null
     */
    public function getOrderByPlatformId($platformOrderID)
    {
        return $this->orderRepository->findByPlatformId($platformOrderID);
    }
}
