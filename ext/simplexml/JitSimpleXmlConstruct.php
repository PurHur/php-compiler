<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::__construct() — user-script AOT (#19306). */
final class JitSimpleXmlConstruct
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SimpleXMLElement::__construct', 1, 5)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if (\count($args) < 2) {
            throw new \LogicException('SimpleXMLElement::__construct() expects receiver and data');
        }
        $stored = JitSimpleXmlUserScript::tryConstruct($context, ...$args);
        if (null === $stored) {
            // Host SimpleXMLElement rejected the compile-time literal (undeclared entity, …).
            // Surface Zend's Exception message at compile time; full catchable AOT throw for
            // parse failures needs a non-detached builder (follow-up). VM path is SSOT (#22775).
            if (JitSimpleXmlUserScript::lastConstructParseFailed()) {
                throw new \Exception('String could not be parsed as XML');
            }
            throw new \LogicException(
                'SimpleXMLElement::__construct() user-script AOT requires a compile-time string literal (#19306)'
            );
        }
        // Return $this (not null): FUNCCALL_EXEC_RETURN must not clobber the new object (#19306).
        if (JITVariable::TYPE_OBJECT === $args[0]->type) {
            $obj = $context->helper->loadValue($args[0]);
            $context->type->object->markObjectConstructed($obj);
            $slot = JitValueBox::alloc($context);
            $context->builder->call(
                $context->lookupFunction('__value__writeObject'),
                JitValueBox::pointer($context, $slot),
                $obj
            );

            return JitValueBox::normalizeValuePtr($context, $slot);
        }

        return $stored;
    }
}
