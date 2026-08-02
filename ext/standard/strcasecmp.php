<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\StringStrcasecmp;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * strcasecmp() for two strings (ASCII case fold subset; JIT via CaseCompareJitHelper PHP #15225).
 */
final class strcasecmp extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src ext/standard/string.c — ArgumentCountError (#23164).
        $this->requireExactArgCount($frame, 'strcasecmp', 2);
        $a = self::vmStringArg($frame, 0, 'string1');
        $b = self::vmStringArg($frame, 1, 'string2');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->int(VmString::strcasecmp($a, $b));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        if (!$this->requireExactJitArgCount($context, $args, 'strcasecmp', 2)) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }
        StringStrcasecmp::ensureLinked($context);
        $p0 = $this->stringDataPtr($context, self::jitStringArg($context, $args[0], 0, 'string1'));
        $p1 = $this->stringDataPtr($context, self::jitStringArg($context, $args[1], 1, 'string2'));
        $fn = $context->lookupFunction(\PHPCompiler\JIT\Builtin\StringCaseCompare::ABI_STRCASECMP);
        $raw = $context->builder->call($fn, $p0, $p1);
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sExt($raw, $i64);
    }

    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'strcasecmp', $paramName)->toString();
        }

        // Soft-null on forward profile — Zend 8.4 deprecate+coerce (#21190).
        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'strcasecmp',
            $argIndex,
            $paramName
        );
    }

    private static function jitStringArg(
        Context $context,
        JITVariable $arg,
        int $argIndex,
        string $paramName
    ): Value {
        if ($context->callerStrictTypes) {
            return JitStringBuiltinArg::lowerStrictOrCoercible(
                $context,
                $arg,
                'strcasecmp',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'strcasecmp',
            $argIndex,
            $paramName
        );
    }
}
