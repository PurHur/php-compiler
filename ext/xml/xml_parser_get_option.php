<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** xml_parser_get_option() — read parser option (php-src ext/xml/xml.c; #18203). */
final class xml_parser_get_option extends XmlFunction
{
    public function __construct()
    {
        parent::__construct('xml_parser_get_option');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('xml_parser_get_option() expects exactly 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_parser_get_option', 1);
        $optionArg = $frame->calledArgs[1]->resolveIndirect();
        $option = Variable::TYPE_INTEGER === $optionArg->type ? $optionArg->toInt() : (int) $optionArg->toString();
        $value = XmlParserHandlers::getOption($parser, $option);
        if (\is_int($value)) {
            $frame->returnVar->int($value);
        } elseif (\is_string($value)) {
            $frame->returnVar->string($value);
        } else {
            $frame->returnVar->bool((bool) $value);
        }
    }
}
