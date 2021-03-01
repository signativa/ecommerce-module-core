<?php

namespace Mundipagg\Core\Kernel\ValueObjects;

use Mundipagg\Core\Kernel\Abstractions\AbstractValueObject;

/**
 * Class TransactionStatusError
 * @copyright Signativa
 * @package Mundipagg\Core\Kernel\ValueObjects
 */
final class TransactionStatusError extends AbstractValueObject
{
    /**
     * @var TransactionStatus
     */
    protected $status;

    /**
     * OrderStatus constructor.
     *
     * @param TransactionStatus $transactionStatus
     */
    public function __construct($transactionStatus)
    {
        $this->status = $transactionStatus;
    }

    /**
     * @return string|null
     */
    public function getMessage()
    {
        $messages = $this->getMessages();

        if(array_key_exists($this->getStatus(), $messages) == false)
            return null;

        return $messages[$this->getStatus()];
    }

    /**
     * @return string[]
     */
    public function getMessages()
    {
        return [
            TransactionStatus::PARTIAL_REFUNDED => 'Estornada parcialmente',
            TransactionStatus::PARTIAL_CAPTURE => 'Capturada parcialmente',
            TransactionStatus::CAPTURED => 'Capturada',
            TransactionStatus::AUTHORIZED_PENDING_CAPTURE => 'Autorizada pendente de captura',
            TransactionStatus::VOIDED => 'Cancelada',
            TransactionStatus::PARTIAL_VOID => 'Estornada parcialmente',
            TransactionStatus::GENERATED => 'Gerada',
            TransactionStatus::UNDERPAID => 'Não pago',
            TransactionStatus::PAID => 'Pago',
            TransactionStatus::OVERPAID => 'OverPaid',
            TransactionStatus::WITH_ERROR => 'Com erro',
            TransactionStatus::NOT_AUTHORIZED => 'Não autorizada',
            TransactionStatus::REFUNDED => 'Estornada',
            TransactionStatus::FAILED => 'Falha',
            TransactionStatus::WAITING_PAYMENT => 'Aguardando Pagamento',
            TransactionStatus::PENDING_REFUND => 'Aguardando Estorno',
            TransactionStatus::EXPIRED => 'Expirada'
        ];
    }

    /**
     * @return string
     */
    private function getStatus()
    {
        return $this->status->getStatus();
    }

    protected function isEqual($object)
    {
        // TODO: Implement jsonSerialize() method.
    }

    public function jsonSerialize()
    {
        // TODO: Implement jsonSerialize() method.
    }
}
