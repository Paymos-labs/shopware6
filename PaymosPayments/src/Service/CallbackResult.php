<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

/**
 * The outcome of processing a Paymos webhook: the HTTP status the controller
 * must return (the server's retry logic depends on it) plus a short body.
 */
final class CallbackResult
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $message;

    /** @var bool */
    private $duplicate;

    public function __construct($statusCode, $message, $duplicate = false)
    {
        $this->statusCode = (int) $statusCode;
        $this->message = (string) $message;
        $this->duplicate = (bool) $duplicate;
    }

    public function statusCode()
    {
        return $this->statusCode;
    }

    public function message()
    {
        return $this->message;
    }

    public function isDuplicate()
    {
        return $this->duplicate;
    }
}
