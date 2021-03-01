<?php

namespace Mundipagg\Core\Payment\Services\ResponseHandlerExceptions;

/**
 * Interface IExceptionHandler
 * @copyright Signativa
 * @package Mundipagg\Core\Payment\Services
 */
interface IHandler
{
    public function setNext(IHandler $handler);

    public function handle($response);
}
