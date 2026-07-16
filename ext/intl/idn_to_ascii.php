<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * idn_to_ascii() — Unicode domain → ASCII/punycode (php-src ext/intl/idn/idn.c; #6169).
 */
final class idn_to_ascii extends IdnFunction
{
    public function __construct()
    {
        parent::__construct('idn_to_ascii');
    }

    protected function convertMode(): bool
    {
        return true;
    }
}
