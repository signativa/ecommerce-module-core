<?php

namespace Mundipagg\Core\Webhook\Services;

use Exception;
use Mundipagg\Core\Kernel\Abstractions\AbstractModuleCoreSetup as MPSetup;
use Mundipagg\Core\Kernel\Exceptions\NotFoundException;
use Mundipagg\Core\Recurrence\Aggregates\Charge;
use Mundipagg\Core\Kernel\Aggregates\Order;
use Mundipagg\Core\Kernel\Exceptions\InvalidParamException;
use Mundipagg\Core\Kernel\Factories\OrderFactory;
use Mundipagg\Core\Kernel\Interfaces\ChargeInterface;
use Mundipagg\Core\Kernel\Interfaces\PlatformOrderInterface;
use Mundipagg\Core\Kernel\Services\APIService;
use Mundipagg\Core\Kernel\Services\LocalizationService;
use Mundipagg\Core\Kernel\Services\MoneyService;
use Mundipagg\Core\Kernel\Services\OrderService;
use Mundipagg\Core\Kernel\ValueObjects\ChargeStatus;
use Mundipagg\Core\Kernel\ValueObjects\Id\SubscriptionId;
use Mundipagg\Core\Kernel\ValueObjects\OrderStatus;
use Mundipagg\Core\Recurrence\Repositories\ChargeRepository;
use Mundipagg\Core\Recurrence\Repositories\SubscriptionRepository;
use Mundipagg\Core\Webhook\Aggregates\Webhook;
use Mundipagg\Core\Kernel\Repositories\OrderRepository;
use Mundipagg\Core\Kernel\ValueObjects\OrderState;

final class ChargeRecurrenceService extends AbstractHandlerService
{
    /**
     * @param Webhook $webhook
     * @return array
     * @throws InvalidParamException
     */
    public function handlePaid(Webhook $webhook)
    {
        $orderFactory = new OrderFactory();
        $chargeRepository = new ChargeRepository();
        $orderService = new OrderService();

        /**
         * @var Charge $charge
         */
        $charge = $webhook->getEntity();

        $transaction = $charge->getLastTransaction();

        /**
         * @var Charge $outdatedCharge
         */
        $outdatedCharge = $chargeRepository->findByMundipaggId($charge->getMundipaggId());
        if ($outdatedCharge !== null) {
            $outdatedCharge->addTransaction($charge->getLastTransaction());
            $charge = $outdatedCharge;
        }

        $paidAmount = $transaction->getPaidAmount();
        $platformOrder = $this->order->getPlatformOrder();

        if (!$charge->getStatus()->equals(ChargeStatus::paid())) {
            $charge->pay($paidAmount);
        }

        if ($charge->getPaidAmount() == 0) {
            $charge->setPaidAmount($paidAmount);
        }

        $chargeRepository->save($charge);

        $this->order->setCurrentCharge($charge);

        $history = $this->prepareHistoryComment($charge);
        $platformOrder->addHistoryComment($history);

        $platformOrderStatus = ucfirst(ChargeStatus::paid()->getStatus());
        $realOrder = $orderFactory->createFromSubscriptionData(
            $this->order,
            $platformOrderStatus
        );
        $realOrder->addCharge($charge);

        $orderService->syncPlatformWith($realOrder);

        $this->addWebHookReceivedHistory($webhook);
        $platformOrder->save();

        $returnMessage = $this->prepareReturnMessage($charge);
        $result = [
            "message" => $returnMessage,
            "code" => 200
        ];

        return $result;
    }

    /**
     * @param Webhook $webhook
     * @return array
     * @throws InvalidParamException
     */
    protected function handlePartialCanceled(Webhook $webhook)
    {
        $orderFactory = new OrderFactory();
        $chargeRepository = new ChargeRepository();
        $orderService = new OrderService();

        $order = $this->order;

        /**
         *
         * @var Charge $charge
         */
        $charge = $webhook->getEntity();

        $transaction = $charge->getLastTransaction();
        /**
         *
         * @var Charge $outdatedCharge
         */
        $outdatedCharge = $chargeRepository->findByMundipaggId(
            $charge->getMundipaggId()
        );

        if ($outdatedCharge !== null) {
            $charge = $outdatedCharge;
        }

        $cancelAmount = $charge->getCanceledAmount();
        if ($transaction !== null) {
            $charge->addTransaction($transaction);
            $cancelAmount = $transaction->getAmount();
        }

        $charge->cancel($cancelAmount);
        $chargeRepository->save($charge);

        $this->order->setCurrentCharge($charge);

        $history = $this->prepareHistoryComment($charge);
        $order->getPlatformOrder()->addHistoryComment($history);

        $platformOrderStatus = ucfirst($order->getPlatformOrder()->getPlatformOrder()->getStatus());
        $realOrder = $orderFactory->createFromSubscriptionData(
            $order,
            $platformOrderStatus
        );
        $realOrder->addCharge($charge);

        $orderService->syncPlatformWith($realOrder);

        $this->addWebHookReceivedHistory($webhook);
        $returnMessage = $this->prepareReturnMessage($charge);
        $result = [
            "message" => $returnMessage,
            "code" => 200
        ];

        return $result;
    }

    protected function handleOverpaid(Webhook $webhook)
    {
        return $this->handlePaid($webhook);
    }

    protected function handleUnderpaid(Webhook $webhook)
    {
        return $this->handlePaid($webhook);
    }

    protected function handleRefunded(Webhook $webhook)
    {
        $orderFactory = new OrderFactory();
        $chargeRepository = new ChargeRepository();
        $orderService = new OrderService();

        $order = $this->order;
        if ($order->getStatus()->equals(OrderStatus::canceled())) {
            $result = [
                "message" => "It is not possible to refund a charge of an order that was canceled.",
                "code" => 200
            ];
            return $result;
        }

        /**
         * @var Charge $charge
         */
        $charge = $webhook->getEntity();

        $transaction = $charge->getLastTransaction();

        /**
         * @var Charge $outdatedCharge
         */
        $outdatedCharge = $chargeRepository->findByMundipaggId(
            $charge->getMundipaggId()
        );

        if ($outdatedCharge !== null) {
            $charge = $outdatedCharge;
        }

        $cancelAmount = $charge->getAmount();
        if ($transaction !== null) {
            $charge->addTransaction($transaction);
            $cancelAmount = $transaction->getAmount();
        }

        $charge->cancel($cancelAmount);
        $chargeRepository->save($charge);

        $this->order->setCurrentCharge($charge);

        $history = $this->prepareHistoryComment($charge);
        $order->getPlatformOrder()->addHistoryComment($history);

        $platformOrderStatus = ucfirst($order->getPlatformOrder()->getPlatformOrder()->getStatus());
        $realOrder = $orderFactory->createFromSubscriptionData(
            $order,
            $platformOrderStatus
        );
        $realOrder->addCharge($charge);

        $orderService->syncPlatformWith($realOrder);

        $this->addWebHookReceivedHistory($webhook);
        $returnMessage = $this->prepareReturnMessage($charge);
        $result = [
            "message" => $returnMessage,
            "code" => 200
        ];

        return $result;
    }

    //@todo handleProcessing
    protected function handleProcessing_TODO(Webhook $webhook)
    {
        //@todo
        //In simulator, Occurs with values between 1.050,01 and 1.051,71, auth
        // only and auth and capture.
        //AcquirerMessage = Simulator|Ocorreu um timeout (transação simulada)
    }

    //@todo handlePaymentFailed
    protected function handlePaymentFailed_TODO(Webhook $webhook)
    {
        //@todo
        //In simulator, Occurs with values between 1.051,72 and 1.262,06, auth
        // only and auth and capture.
        //AcquirerMessage = Simulator|Transação de simulação negada por falta de crédito, utilizado para realizar simulação de autorização parcial
        //ocurrs in the next case of the simulator too.

        //When this webhook is received, the order wasn't created on magento, so
        // no further action is needed.
    }

    //@todo handleCreated
    protected function handleCreated_TODO(Webhook $webhook)
    {
        //@todo, but not with priority,
    }

    //@todo handlePending
    protected function handlePending_TODO(Webhook $webhook)
    {
        //@todo, but not with priority,
    }

    /**
     * @param Webhook $webhook
     * @throws InvalidParamException
     * @throws Exception
     */
    public function loadOrder(Webhook $webhook)
    {
        $subscriptionRepository = new SubscriptionRepository();
        $apiService = new ApiService();

        /** @var Charge $charge */
        $charge = $webhook->getEntity();

        $subscriptionId = $charge->getInvoice()->getSubscriptionId();
        $subscription = $apiService->getSubscription(new SubscriptionId($subscriptionId));

        if (is_null($subscription)) {
            throw new Exception('Code não foi encontrado', 400);
        }

        $charge->setCycleStart($subscription->getCurrentCycle()->getCycleStart());
        $charge->setCycleEnd($subscription->getCurrentCycle()->getCycleEnd());

        $orderCode = $subscription->getPlatformOrder()->getCode();
        $order = $subscriptionRepository->findByCode($orderCode);
        if ($order === null) {
            throw new NotFoundException("Order #{$orderCode} not found.");
        }

        $this->order = $order;
    }

    /**
     * @param ChargeInterface $charge
     * @return mixed|string
     * @throws InvalidParamException
     */
    public function prepareHistoryComment(ChargeInterface $charge)
    {
        $i18n = new LocalizationService();
        $moneyService = new MoneyService();

        if (
            $charge->getStatus()->equals(ChargeStatus::paid())
            || $charge->getStatus()->equals(ChargeStatus::overpaid())
            || $charge->getStatus()->equals(ChargeStatus::underpaid())
        ) {
            $amountInCurrency = $moneyService->centsToFloat($charge->getPaidAmount());

            $history = $i18n->getDashboard(
                'Payment received: %.2f',
                $amountInCurrency
            );

            $extraValue = $charge->getPaidAmount() - $charge->getAmount();
            if ($extraValue > 0) {
                $history .= ". " . $i18n->getDashboard(
                    "Extra amount paid: %.2f",
                    $moneyService->centsToFloat($extraValue)
                    );
            }

            if ($extraValue < 0) {
                $history .= ". " . $i18n->getDashboard(
                    "Remaining amount: %.2f",
                    $moneyService->centsToFloat(abs($extraValue))
                    );
            }

            $refundedAmount = $charge->getRefundedAmount();
            if ($refundedAmount > 0) {
                $history = $i18n->getDashboard(
                    'Refunded amount: %.2f',
                    $moneyService->centsToFloat($refundedAmount)
                );
                $history .= " (" . $i18n->getDashboard('until now') . ")";
            }

            $canceledAmount = $charge->getCanceledAmount();
            if ($canceledAmount > 0) {
                $amountCanceledInCurrency = $moneyService->centsToFloat($canceledAmount);

                $history .= " ({$i18n->getDashboard('Partial Payment')}";
                $history .= ". " .
                    $i18n->getDashboard(
                        'Canceled amount: %.2f',
                        $amountCanceledInCurrency
                    ) . ')';
            }

            return $history;
        }

        $amountInCurrency = $moneyService->centsToFloat($charge->getRefundedAmount());
        $history = $i18n->getDashboard(
            'Charge canceled.'
        );

        $history .= ' ' . $i18n->getDashboard(
            'Refunded amount: %.2f',
            $amountInCurrency
            );

        $history .= " (" . $i18n->getDashboard('until now') . ")";

        return $history;
    }

    /**
     * @param ChargeInterface $charge
     * @return string
     * @throws InvalidParamException
     */
    public function prepareReturnMessage(ChargeInterface $charge)
    {
        $moneyService = new MoneyService();

        if (
            $charge->getStatus()->equals(ChargeStatus::paid())
            || $charge->getStatus()->equals(ChargeStatus::overpaid())
            || $charge->getStatus()->equals(ChargeStatus::underpaid())
        ) {
            $amountInCurrency = $moneyService->centsToFloat($charge->getPaidAmount());

            $returnMessage = "Amount Paid: $amountInCurrency";

            $extraValue = $charge->getPaidAmount() - $charge->getAmount();
            if ($extraValue > 0) {
                $returnMessage .= ". Extra value paid: " .
                    $moneyService->centsToFloat($extraValue);
            }

            if ($extraValue < 0) {
                $returnMessage .= ". Remaining Amount: " .
                    $moneyService->centsToFloat(abs($extraValue));
            }

            $canceledAmount = $charge->getCanceledAmount();
            if ($canceledAmount > 0) {
                $amountCanceledInCurrency = $moneyService->centsToFloat($canceledAmount);

                $returnMessage .= ". Amount Canceled: $amountCanceledInCurrency";
            }


            $refundedAmount = $charge->getRefundedAmount();
            if ($refundedAmount > 0) {
                $returnMessage = "Refunded amount unil now: " .
                    $moneyService->centsToFloat($refundedAmount);
            }

            return $returnMessage;
        }

        $amountInCurrency = $moneyService->centsToFloat($charge->getRefundedAmount());
        $returnMessage = "Charge canceled. Refunded amount: $amountInCurrency";

        return $returnMessage;
    }
}