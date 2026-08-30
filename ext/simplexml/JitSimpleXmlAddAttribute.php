<?php

declare(strict_types=1);

namespace PHPCompiler\ext\simplexml;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** LLVM lowering for SimpleXMLElement::addAttribute() — user-script AOT (#35806 / peer #19306). */
final class JitSimpleXmlAddAttribute
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        if (!VmClassMethod::requireJitUserArgCountRange($context, $args, 'SimpleXMLElement::addAttribute', 1, 3)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }
        if (\count($args) < 2) {
            throw new \LogicException('SimpleXMLElement::addAttribute() expects receiver and qualifiedName');
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
