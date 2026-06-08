<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;

/** libxml_get_errors() — return buffered LibXMLError objects (php-src ext/libxml/libxml.c; #6058). */
final class libxml_get_errors extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_get_errors');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->array(VmLibxml::getErrors($frame->vmContext));
    }
}
