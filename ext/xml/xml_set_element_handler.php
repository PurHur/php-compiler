<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;

/** xml_set_element_handler() — register start/end element callbacks (php-src ext/xml/xml.c; #18203). */
final class xml_set_element_handler extends XmlSetHandlerFunction
{
    public function __construct()
    {
        parent::__construct('xml_set_element_handler');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError($this->getName().'() expects 1 to 3 arguments, '.$argc.' given');
        }
        $this->setDualHandler(
            $frame,
            XmlParserHandlers::HANDLER_ELEMENT_START,
            XmlParserHandlers::HANDLER_ELEMENT_END,
            1,
            2
        );
    }
}
