<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * xml_parser_free() — documented no-op since PHP 8.0 (php-src ext/xml/xml.c; #11987, #22813).
 *
 * Validates XMLParser and returns true; GC owns teardown (unset/$parser = null).
 */
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

        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_parser_free', 1);

        $frame->returnVar->bool(VmXml::parserFree($parser->id));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (JitXmlParserUserScript::isUserScriptAot()) {
            $result = JitXmlParserUserScript::tryFree($context, ...$args);
            if (null !== $result) {
                return $result;
            }
            throw new \LogicException(
                'xml_parser_free() user-script AOT requires a tracked XMLParser (#29318)'
            );
        }
        throw new \LogicException('xml_parser_free() is not JIT-lowered in this compiler build');
    }
}
