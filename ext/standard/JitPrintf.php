<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for printf() (%s, %d, %f, %%).
 *
 * Z_PARAM_STR $format: Zend 8.4 DEP+coerces null (#21234; reverts #20197 TypeError).
 */
final class JitPrintf
{
    public static function format(Context $context, JITVariable ...$args): Value
    {
        // User-standalone init skips StringFormat::ensureLinked (#13571) —
        // without a body the ABI symbols die at link with undefined
        // __compiler_printf (same as JitSprintf #15642).
        if ('1' !== getenv('PHP_COMPILER_HELPER_RUNTIME_EMITTING')) {
            \PHPCompiler\JIT\Builtin\StringFormat::implementIfDeclared($context, true);
        }
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError(\sprintf(
                'printf() expects at least 1 argument, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR — Zend 8.4 DEP+coerces null (#21234, formatted_print.c).
        $nullFormat = JITVariable::TYPE_NULL === $args[0]->type || ($args[0]->isNullConstant ?? false);
        $fmt = $context->callerStrictTypes
            ? JitStringBuiltinArg::lowerStrictOrCoercible($context, $args[0], 'printf', 0, 'format')
            : JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'printf', 0, 'format');
        if ($nullFormat && $context->callerStrictTypes) {
            // lowerStrict* already emitted TypeError+abort; do not lower __compiler_printf after terminator.
            return $context->constantFromInteger(0, 'int64');
        }
        $numArgs = $argc - 1;
        if (0 === $numArgs) {
            $nullArgv = $context->builder->pointerCast(
                $context->getTypeFromString('int64')->constInt(0, false),
                $context->getTypeFromString('__value__*')
            );

            return $context->builder->intCast(
                $context->builder->call(
                    $context->lookupFunction('__compiler_printf'),
                    $fmt,
                    $context->getTypeFromString('int64')->constInt(0, false),
                    $nullArgv
                ),
                $context->getTypeFromString('int64')
            );
        }

        // Multi-arg: format via JitSprintf (direct snprintf), then echo the result.
        \PHPCompiler\JIT\Builtin\StringFormat::ensureRuntimeHelpersPublic($context);
        $formatted = JitSprintf::formatWithFmt($context, $fmt, ...array_slice($args, 1));

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $stringMap = $context->structFieldMap['__string__'];

        $data = $context->builder->structGep($formatted, $stringMap['value']);
        $len = $context->builder->load($context->builder->structGep($formatted, $stringMap['length']));
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $data,
            $len
        );

        return $context->builder->zExt($len, $i64);
    }
}
