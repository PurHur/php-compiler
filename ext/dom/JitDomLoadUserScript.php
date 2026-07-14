<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dom;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * User-script standalone AOT: compile-time DOMDocument::load() (#18897).
 *
 * When the filename is a compile-time literal, read the file during JIT compile
 * and seed {@see JitDomLoadXMLUserScript} so documentElement/saveXML probes work.
 */
final class JitDomLoadUserScript
{
    public static function shouldUse(Context $context): bool
    {
        return JitDomLoadHTMLUserScript::shouldUse($context);
    }

    public static function tryInvoke(Context $context, JITVariable ...$args): ?Value
    {
        if (\count($args) < 2) {
            return null;
        }

        $path = JitStringBuiltinArg::compileTimeLiteral($args[1]) ?? $args[1]->compileTimeString;
        if (null === $path || '' === trim($path)) {
            return null;
        }

        if (null !== JitDomLoadXMLUserScript::lastCompileTimeXml()) {
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

        $xml = @\file_get_contents($path);
        if (false === $xml || '' === trim($xml)) {
            return null;
        }

        JitDomLoadXMLUserScript::rememberCompileTimeXml($xml);

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
