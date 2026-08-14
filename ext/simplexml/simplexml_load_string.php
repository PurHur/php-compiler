<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** simplexml_load_string() — parse XML into SimpleXMLElement tree (#3338, #22406, #26863). */
final class simplexml_load_string extends Internal
{
    public function __construct()
    {
        parent::__construct('simplexml_load_string');
    }

    public function execute(Frame $frame): void
    {
        // php-src simplexml.stub.php: simplexml_load_string($data, $class_name = SimpleXMLElement::class, $options = 0, $namespace_or_prefix = "", $is_prefix = false) (#30828).
        $this->requireArgCountRange($frame, 'simplexml_load_string', 1, 5);
        if (null === $frame->vmContext) {
            throw new \LogicException('simplexml_load_string() requires VM context');
        }
        // Z_PARAM_STR $data — soft-null DEP+coerce on PROFILE=8.4 (#21502, reverts #20352 TypeError;
        // php-src ext/simplexml/simplexml.c — Zend deprecates null → '', then returns false).
        $data = VmString::trimFamilyStringArgForFrame(
            $frame,
            0,
            'simplexml_load_string',
            0,
            'data'
        );
        $className = null;
        if (isset($frame->calledArgs[1])) {
            $classArg = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $classArg->type) {
                $className = VmString::coerceStringBuiltinArg(
                    $frame->calledArgs[1],
                    'simplexml_load_string',
                    1,
                    'class_name'
                );
            }
        }
        $class = VmSimpleXml::resolveClass($frame->vmContext, $className, 'simplexml_load_string');
        $entry = VmSimpleXml::loadString($frame->vmContext, $data, $frame, $class);
        if (null !== $frame->returnVar) {
            if (null === $entry) {
                $frame->returnVar->bool(false);
            } else {
                $frame->returnVar->object($entry);
            }
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireArgCountRangeJit($context, $args, 'simplexml_load_string', 1, 5)) {
            return JitValueBox::pointer($context, JitValueBox::alloc($context));
        }

        return JitSimpleXmlLoadString::invoke($context, ...$args);
    }
}
