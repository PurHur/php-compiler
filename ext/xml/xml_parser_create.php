<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** xml_parser_create() — allocate SAX parser handle (php-src ext/xml/xml.c; #7406, #6058). */
final class xml_parser_create extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_parser_create');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(XmlParserSupport::createParser($frame->vmContext));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (JitXmlParserUserScript::isUserScriptAot()) {
            $result = JitXmlParserUserScript::tryCreate($context, ...$args);
            if (null !== $result) {
                return $result;
            }
            throw new \LogicException(
                'xml_parser_create() user-script AOT requires at most one compile-time encoding (#27293)'
            );
        }
        throw new \LogicException('xml_parser_create() is not JIT-lowered in this compiler build');
    }
}
