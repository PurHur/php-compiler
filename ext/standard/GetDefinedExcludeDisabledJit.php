<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\CompilerVersion;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;

/** Shared JIT arg parsing for get_defined_functions() $exclude_disabled (#4942). */
final class GetDefinedExcludeDisabledJit
{
    /**
     * @param list<JITVariable> $args
     */
    public static function parseLiteral(Context $context, array $args, string $function): ?bool
    {
        $argc = \count($args);
        if (!CompilerVersion::supportsGetDefinedFunctionsExcludeDisabled()) {
            if ($argc > 0) {
                TypeErrorRaise::ensureLinked($context);
                TypeErrorRaise::emitArgumentCountError(
                    $context,
                    "{$function}() expects exactly 0 arguments, {$argc} given"
                );

                return false;
            }

            return false;
        }
        if ($argc > 1) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                "{$function}() expects at most 1 argument, {$argc} given"
            );

            return false;
        }
        if (0 === $argc) {
            return false;
        }

        $arg = $args[0];
        $literalBool = self::compileTimeBool($context, $arg);
        if (null !== $literalBool) {
            return $literalBool;
        }
        $literal = JitStringArg::compileTimeLiteral($arg);
        if (null !== $literal) {
            $lower = strtolower($literal);
            if (\in_array($lower, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (\in_array($lower, ['0', 'false', 'off', 'no', ''], true)) {
                return false;
            }
        }

        return null;
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type
            && JITVariable::KIND_VALUE === $var->kind) {
            $raw = $var->value->value ?? null;
            if (null === $raw) {
                return null;
            }
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($raw)) {
                return 0 !== (int) $lib->LLVMConstIntGetZExtValue($raw);
            }
        }

        return null;
    }

    /**
     * @param list<JITVariable> $args
     */
    public static function lowerRuntimeBool(Context $context, array $args, string $function): \PHPLLVM\Value
    {
        return JitBoolArg::lower(
            $context,
            $args[0],
            "{$function}(): Argument #1 (\$exclude_disabled)"
        );
    }
}
