<?php

declare(strict_types=1);

namespace PHPCompiler\ext\xml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** xml_parse() — feed parser; malformed XML records libxml errors (#3494, #6058, soft-null #21505). */
final class xml_parse extends Internal
{
    public function __construct()
    {
        parent::__construct('xml_parse');
    }

    public function execute(Frame $frame): void
    {
        // php-src xml.stub.php: xml_parse(..., bool $is_final = false) — at-most/at-least (#30890).
        $this->requireArgCountRange($frame, 'xml_parse', 2, 3);
        $argc = \count($frame->calledArgs);
        if (null === $frame->returnVar) {
            return;
        }

        $parser = XmlParserSupport::requireParser($frame->calledArgs[0], 'xml_parse', 1);
        // Z_PARAM_STR $data — soft-null DEP+coerce on forward profile (#21505;
        // php-src ext/xml/xml.c — Zend still deprecates null → '', parse succeeds as '').
        $data = VmString::trimFamilyStringArgForFrame($frame, 1, 'xml_parse', 1, 'data');

        // php-src stub: bool $is_final = false (ext/xml/xml.stub.php; #21505).
        $isFinal = false;
        if (3 === $argc) {
            $finalArg = $frame->calledArgs[2]->resolveIndirect();
            $isFinal = Variable::TYPE_NULL === $finalArg->type ? false : (bool) $finalArg->toInt();
        }

        $status = VmXml::parse(
            $frame->vmContext,
            $parser->id,
            $data,
            $isFinal,
            $frame,
            $parser
        );
        $frame->returnVar->int($status);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30890).
        if (!$this->requireArgCountRangeJit($context, $args, 'xml_parse', 2, 3)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }
        if (JitXmlParserUserScript::isUserScriptAot()) {
            $result = JitXmlParserUserScript::tryParse($context, ...$args);
            if (null !== $result) {
                return $result;
            }
            throw new \LogicException(
                'xml_parse() user-script AOT requires a tracked parser + compile-time data (#27293)'
            );
        }
        throw new \LogicException('xml_parse() is not JIT-lowered in this compiler build');
    }
}
