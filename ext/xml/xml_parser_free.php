<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_parser_free() — release SAX parser handle (php-src ext/xml/xml.c; #11987). */
final class xml_parser_free extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_parser_free');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('xml_parser_free() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $parserArg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $parserArg->type) {
            throw new \TypeError('xml_parser_free(): Argument #1 ($parser) must be of type XMLParser');
        }

        $frame->returnVar->bool(VmXml::parserFree($parserArg->toInt()));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_parser_free() is not JIT-lowered in this compiler build');
    }
}
