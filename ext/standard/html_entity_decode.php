<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * html_entity_decode() — decode HTML entities; default ENT_QUOTES | ENT_SUBSTITUTE (php-src html.c).
 */
final class html_entity_decode extends Internal
{
    public function __construct()
    {
        parent::__construct('html_entity_decode');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/html.stub.php — ArgumentCountError (#28317, peer #28285).
        $this->requireArgCountRange($frame, 'html_entity_decode', 1, 3);
        $string = self::vmStringArg($frame, 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        $encoding = 'UTF-8';
        $argc = \count($frame->calledArgs);
        if ($argc >= 2) {
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31212).
            $flags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'html_entity_decode',
                2,
                'flags'
            );
        }
        if ($argc >= 3) {
            $encoding = self::resolveEncodingVm($frame->calledArgs[2]->resolveIndirect());
        }
        $frame->returnVar->string(VmString::html_entity_decode($string, $flags, $encoding));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer htmlspecialchars #28285 / #28317.
        if ($argc < 1 || $argc > 3) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('html_entity_decode() expects at least 1 argument, %d given', $argc)
                    : \sprintf('html_entity_decode() expects at most 3 arguments, %d given', $argc)
            );

            return $unreachable;
        }

        $effectiveArgc = self::jitEffectiveArgc($argc, $args);
        // Soft-null outside strict_types; strict → TypeError (#31212).
        if ($argc >= 2
            && $context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'html_entity_decode', 2, 'flags');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'html_entity_decode_null_flags_te_cont');

            return $context->getTypeFromString('__string__*')->constNull();
        }
        if ($effectiveArgc >= 3) {
            return JitHtmlEntityDecode::decodeWithEncoding(
                $context,
                JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'html_entity_decode', 0, 'string'),
                $argc >= 2
                    ? JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'html_entity_decode', 2, 'flags')
                    : $context->getTypeFromString('int64')->constInt(ENT_QUOTES | ENT_SUBSTITUTE, false),
                JitStringBuiltinArg::lower($context, $args[2], 'html_entity_decode', 2, 'encoding')
            );
        }

        $literal = $args[0]->compileTimeString ?? null;
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        $flagsKnown = $argc < 2;
        if ($argc >= 2) {
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                $flagsKnown = false;
            } else {
                $ct = self::tryCompileTimeFlags($context, $args[1]);
                if (null !== $ct) {
                    $flags = $ct;
                    $flagsKnown = true;
                }
            }
        }
        if (null !== $literal && $flagsKnown) {
            return $context->builder->load(
                $context->constantStringFromString(
                    VmString::html_entity_decode($literal, $flags)
                )
            );
        }

        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'html_entity_decode', 0, 'string');
        $i64 = $context->getTypeFromString('int64');
        $flagsVal = $i64->constInt($flags, false);
        if ($argc >= 2 && !$flagsKnown) {
            $flagsVal = JitIntdiv::lowerIntBuiltinArgForCaller(
                $context,
                $args[1],
                'html_entity_decode',
                2,
                'flags'
            );
        }

        return JitHtmlEntityDecode::decode($context, $str, $flagsVal);
    }

    /** Soft-null — coerce+deprecate on forward profile (#21180, ext/standard/html.c). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'html_entity_decode', $paramName)->toString();
        }

        return VmString::coerceTrimFamilyStringArg(
            $frame->calledArgs[$argIndex],
            'html_entity_decode',
            $argIndex,
            $paramName
        );
    }

    private static function resolveEncodingVm(Variable $encVar): string
    {
        if (Variable::TYPE_NULL === $encVar->type) {
            return 'UTF-8';
        }
        if (Variable::TYPE_STRING !== $encVar->type) {
            throw new \TypeError(
                'html_entity_decode(): Argument #3 ($encoding) must be of type ?string, '
                .self::vmTypeName($encVar->type).' given'
            );
        }

        return $encVar->toString();
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function jitEffectiveArgc(int $argc, array $args): int
    {
        if ($argc >= 3 && self::encodingArgIsNull($args[2])) {
            return 2;
        }

        return $argc;
    }

    private static function encodingArgIsNull(JITVariable $var): bool
    {
        return JITVariable::TYPE_NULL === $var->type || $var->isNullConstant;
    }

    private static function vmTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            Variable::TYPE_RESOURCE => 'resource',
            default => 'unknown type',
        };
    }

    private static function tryCompileTimeFlags(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetZExtValue($arg->value->value);
            }
        }
        if (JITVariable::TYPE_VALUE === $arg->type && JITVariable::KIND_VALUE === $arg->kind) {
            return null;
        }

        return null;
    }
}
