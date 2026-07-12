<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;

/** xml_set_notation_decl_handler() — register notation callback (php-src ext/xml/xml.c; #18203). */
final class xml_set_notation_decl_handler extends XmlSetHandlerFunction
{
    public function __construct()
    {
        parent::__construct('xml_set_notation_decl_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 2) {
            throw new \ArgumentCountError($this->getName().'() expects 1 to 2 arguments, '.$argc.' given');
        }
        $this->setSingleHandler($frame, XmlParserHandlers::HANDLER_NOTATION, 1);
    }
}
