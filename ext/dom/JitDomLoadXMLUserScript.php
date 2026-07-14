<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** User-script standalone AOT: compile-time DOMDocument::loadXML() (#18268). */
final class JitDomLoadXMLUserScript
{
    private static ?string $lastCompileTimeXml = null;

    public static function lastCompileTimeXml(): ?string
    {
        return self::$lastCompileTimeXml;
    }

    public static function rememberCompileTimeXml(string $xml): void
    {
        self::$lastCompileTimeXml = $xml;
    }

    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }

        $lit = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $lit || '' === trim($lit)) {
            return null;
        }

        self::$lastCompileTimeXml = $lit;
        $slot = JitValueBox::alloc($context);
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->builder->zext($i1->constInt(1, false), $i32)
        );

        return JitValueBox::normalizeValuePtr($context, $slot);
    }
}
