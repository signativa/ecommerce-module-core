<?php

namespace Mundipagg\Core\Payment\Services\ResponseHandlerExceptions;

/**
 * Class BaseHandler
 * @copyright Signativa
 * @package Mundipagg\Core\Payment\Services
 */
abstract class AbstractException extends \Exception implements IHandler
{
    /**
     * @var IHandler
     */
    protected $next;

    public function __construct($message = "", $code = 0, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    /**
     * @param IHandler $handler
     */
    public function setNext(IHandler $handler)
    {
        $this->next = $handler;
        return $this;
    }

    public function handle($response): AbstractException
    {
        if(is_null($this->next))
            return $this;

        return $this->next->handle($response);
    }
}
