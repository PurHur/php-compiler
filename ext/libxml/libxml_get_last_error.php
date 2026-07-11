<?php

declare(strict_types=1);

namespace PHPCompiler\ext\libxml;

use PHPCompiler\Frame;

/** libxml_get_last_error() — last buffered LibXMLError or false (php-src ext/libxml/libxml.c; #14186). */
final class libxml_get_last_error extends LibxmlFunction
{
    public function __construct()
    {
        parent::__construct('libxml_get_last_error');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmLibxml::getLastError($frame->vmContext));
    }
}
