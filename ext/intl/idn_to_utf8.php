<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

/**
 * idn_to_utf8() — ASCII/punycode domain → Unicode UTF-8 (php-src ext/intl/idn/idn.c; #6169).
 */
final class idn_to_utf8 extends IdnFunction
{
    public function __construct()
    {
        parent::__construct('idn_to_utf8');
    }

    protected function convertMode(): bool
    {
        return false;
    }
}
