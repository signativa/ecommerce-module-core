<?php

namespace Mundipagg\Core\Payment\Services\ResponseHandlerExceptions;

use Mundipagg\Core\Kernel\Exceptions\InvalidParamException;
use Mundipagg\Core\Kernel\Factories\ChargeFactory;
use Mundipagg\Core\Kernel\Services\ChargeService;
use Mundipagg\Core\Kernel\ValueObjects\TransactionStatusError;

/**
 * Class TransactionExceptionHandler
 * @copyright Signativa
 * @package Mundipagg\Core\Payment\Services\ResponseHandlerExceptions
 */
class TransactionExceptionHandler extends AbstractException
{
    public function __construct($message = "Falha ao processar sua transação", $code = 400, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function handle($response): AbstractException
    {
        $chargeCollection = $this->getChargesFromResponse($response);
        $lastCharge = reset($chargeCollection);

        if(is_null($lastCharge))
            return is_null($this->next) ? $this : $this->next->handle($response);

        $transactionStatus = $lastCharge->getLastTransaction()->getStatus();
        $transactionStatusError = new TransactionStatusError($transactionStatus);
        $message = $transactionStatusError->getMessage();

        $this->message = $message ?? $this->message;

        return $this;
    }

    /**
     * @param $response
     * @return \Mundipagg\Core\Kernel\Aggregates\Charge|\Mundipagg\Core\Kernel\Aggregates\Charge[]
     * @throws InvalidParamException
     */
    protected function getChargesFromResponse($response)
    {
        $chargeFactory = new ChargeFactory();
        $chargeService = new ChargeService();

        /** @var \Mundipagg\Core\Kernel\Aggregates\Charge[] $charges */
        $chargeCollection = [];

        foreach ($response['charges'] as $chargeResponse) {
            $order = ['order' => ['id' => $response['id']]];
            $charge = $chargeFactory->createFromPostData(
                array_merge($chargeResponse, $order)
            );

            $chargeCollection[] = $charge;
        }

        return $chargeCollection;
    }
}
