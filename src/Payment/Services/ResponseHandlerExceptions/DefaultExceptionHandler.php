<?php

namespace Mundipagg\Core\Payment\Services\ResponseHandlerExceptions;

/**
 * Class DefaultExceptionHandler
 * @copyright Signativa
 * @package Mundipagg\Core\Payment\Services\ResponseHandlerExceptions
 */
class DefaultExceptionHandler extends AbstractException
{
    public function __construct($message = "Falha ao processar seu pedido", $code = 400, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public function setNext(IHandler $handler)
    {
        return parent::setNext($handler);
    }
}
