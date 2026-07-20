<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_parse_into_struct() — SAX struct builder (php-src ext/xml/xml.c; #3494, soft-null #21505). */
final class xml_parse_into_struct extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_parse_into_struct');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3 || $argc > 4) {
            throw new \ArgumentCountError('xml_parse_into_struct() expects 3 or 4 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_parse_into_struct', 1);
        // Z_PARAM_STR $data — soft-null DEP+coerce on forward profile (#21505;
        // php-src ext/xml/xml.c — Zend deprecates null → ''; empty data still returns 0).
        $data = VmString::trimFamilyStringArgForFrame($frame, 1, 'xml_parse_into_struct', 1, 'data');

        $parsed = VmXml::parseIntoStruct(
            $frame->vmContext,
            $parser->id,
            $data,
            $frame
        );

        $valuesOut = new Variable();
        $valuesOut->array($parsed['values']);
        $frame->calledArgs[2]->copyFrom($valuesOut);

        if ($argc >= 4) {
            $indexOut = new Variable();
            $indexOut->array($parsed['index']);
            $frame->calledArgs[3]->copyFrom($indexOut);
        }

        $frame->returnVar->int($parsed['status']);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_parse_into_struct() is not JIT-lowered in this compiler build');
    }
}
