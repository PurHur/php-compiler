<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_get_current_byte_index() — parser byte offset diagnostic (php-src ext/xml/xml.c; #18120). */
final class xml_get_current_byte_index extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_get_current_byte_index');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError('xml_get_current_byte_index() expects exactly 1 argument, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_get_current_byte_index', 1);

        $frame->returnVar->int(VmXml::getCurrentByteIndex($parser->id));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('xml_get_current_byte_index() is not JIT-lowered in this compiler build');
    }
}
