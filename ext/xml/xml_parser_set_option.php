<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** xml_parser_set_option() — configure parser (php-src ext/xml/xml.c; #18203). */
final class xml_parser_set_option extends XmlFunction
{
    public function __construct()
    {
        parent::__construct('xml_parser_set_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError('xml_parser_set_option() expects exactly 3 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_parser_set_option', 1);
        $optionArg = $frame->calledArgs[1]->resolveIndirect();
        $option = Variable::TYPE_INTEGER === $optionArg->type ? $optionArg->toInt() : (int) $optionArg->toString();
        $valueArg = $frame->calledArgs[2]->resolveIndirect();
        $value = match ($valueArg->type) {
            Variable::TYPE_STRING => $valueArg->toString(),
            Variable::TYPE_INTEGER => $valueArg->toInt(),
            Variable::TYPE_BOOLEAN => $valueArg->toBool(),
            Variable::TYPE_NULL => null,
            default => $valueArg->toString(),
        };
        $frame->returnVar->bool(XmlParserHandlers::setOption($parser, $option, $value));
    }
}
