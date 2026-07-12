<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** xml_set_object() — bind handler method prefix object (php-src ext/xml/xml.c; #18203). */
final class xml_set_object extends XmlFunction
{
    public function __construct()
    {
        parent::__construct('xml_set_object');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('xml_set_object() expects exactly 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_set_object', 1);
        $objectArg = $frame->calledArgs[1]->resolveIndirect();
        $object = Variable::TYPE_OBJECT === $objectArg->type ? $objectArg->toObject() : null;
        $frame->returnVar->bool(XmlParserHandlers::setObject($parser, $object));
    }
}
