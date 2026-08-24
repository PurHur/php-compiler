<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * xml_parser_create_ns() — allocate namespace-aware SAX parser (php-src ext/xml/xml.c; #19683).
 *
 * Expanded element/attribute names are uri + separator + localname (default separator ":").
 */
final class xml_parser_create_ns extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_parser_create_ns');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $argc = \count($frame->calledArgs);
        if ($argc > 2) {
            throw new \ArgumentCountError('xml_parser_create_ns() expects at most 2 arguments, '.$argc.' given');
        }
        // encoding is accepted for Zend signature parity; expat encodings are not enforced here.
        $separator = ':';
        if ($argc >= 2) {
            $sepArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $sepArg->type) {
                $separator = $sepArg->toString();
            }
        }
        $frame->returnVar->copyFrom(XmlParserSupport::createParser($frame->vmContext, true, $separator));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireAtMostJitArgCount($context, $args, 'xml_parser_create_ns', 2)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        if (JitXmlParserUserScript::isUserScriptAot()) {
            $result = JitXmlParserUserScript::tryCreateNs($context, ...$args);
            if (null !== $result) {
                return $result;
            }
            throw new \LogicException(
                'xml_parser_create_ns() user-script AOT requires compile-time encoding and separator (#34407)'
            );
        }
        throw new \LogicException('xml_parser_create_ns() is not JIT-lowered in this compiler build');
    }
}
