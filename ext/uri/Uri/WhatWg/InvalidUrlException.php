<?php

declare(strict_types=1);

namespace Uri\WhatWg;

class InvalidUrlException extends \Uri\InvalidUriException
{
    /** @var array<int, mixed> */
    public readonly array $errors;

    /**
     * @param array<int, mixed> $errors
     */
    public function __construct(string $message = '', array $errors = [], int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
        $this->errors = $errors;
    }
}
