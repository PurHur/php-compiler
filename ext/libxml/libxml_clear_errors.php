<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;

/** libxml_clear_errors() — empty internal error buffer (php-src ext/libxml/libxml.c; #6058). */
final class libxml_clear_errors extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_clear_errors');
    }

    public function execute(Frame $frame): void
    {
        VmLibxml::clearErrors();
    }
}
