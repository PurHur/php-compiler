<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::addAttribute() — user-script AOT (#35806). */
final class JitSimpleXmlAddAttribute
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SimpleXMLElement::addAttribute', 2, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if (\count($args) < 3) {
            throw new \LogicException('SimpleXMLElement::addAttribute() expects receiver, name, and value');
        }
        $us = JitSimpleXmlUserScript::tryAddAttribute($context, ...$args);
        if (null !== $us) {
            return $us;
        }
        throw new \LogicException(
            'SimpleXMLElement::addAttribute() user-script AOT requires compile-time literal args after construct (#35806)'
        );
    }
}
