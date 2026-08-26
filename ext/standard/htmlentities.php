<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * htmlentities() — full HTML_ENTITIES table; default ENT_QUOTES | ENT_SUBSTITUTE (php-src html.c).
 */
final class htmlentities extends Internal
{
    public function __construct()
    {
        parent::__construct('htmlentities');
    }

    public function execute(Frame $frame): void
    {
        // php-src ext/standard/html.stub.php — ArgumentCountError (#28317, peer #28285).
        $this->requireArgCountRange($frame, 'htmlentities', 1, 4);
        $string = self::vmStringArg($frame, 0, 'string');
        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        $encoding = 'UTF-8';
        $doubleEncode = true;
        if (isset($frame->calledArgs[1])) {
            // Z_PARAM_LONG: caller strict_types → TypeError on null; else soft-null DEP+coerce (#31212).
            $flags = VmMath::parseZParamLongBuiltinArgForFrame(
                $frame,
                1,
                'htmlentities',
                2,
                'flags'
            );
        }
        if (isset($frame->calledArgs[2])) {
            $encoding = self::resolveEncodingVm($frame->calledArgs[2]->resolveIndirect());
        }
        if (isset($frame->calledArgs[3])) {
            // Z_PARAM_BOOL — null→false + E_DEPRECATED (php-src html.c / zend_API.h; peer #29445).
            $doubleEncode = VmMath::parseBoolBuiltinArgForFrame(
                $frame,
                3,
                'htmlentities',
                4,
                'double_encode'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmString::htmlentities($string, $flags, $encoding, $doubleEncode));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $this->context = $context;
        $argc = \count($args);
        // Catchable ArgumentCountError (AOT try/catch) — peer htmlspecialchars #28285 / #28317.
        if ($argc < 1 || $argc > 4) {
            $unreachable = $context->getTypeFromString('__string__*')->constNull();
            \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('htmlentities() expects at least 1 argument, %d given', $argc)
                    : \sprintf('htmlentities() expects at most 4 arguments, %d given', $argc)
            );

            return $unreachable;
        }
        $folded = self::tryCompileTimeHtmlentities($context, $args);
        if (null !== $folded) {
            return $folded;
        }

        self::assertJitUtf8Encoding($args, $argc);

        // Soft-null outside strict_types; strict → TypeError (#31212 / peer htmlspecialchars).
        if ($argc >= 2
            && $context->callerStrictTypes
            && (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false))) {
            JitIntdiv::lowerIntBuiltinArgForCaller($context, $args[1], 'htmlentities', 2, 'flags');
            BasicBlockHelper::ensureOpenInsertBlock($context, 'htmlentities_null_flags_te_cont');

            return $context->getTypeFromString('__string__*')->constNull();
        }

        if ($argc >= 2 && JITVariable::TYPE_NATIVE_LONG !== $args[1]->type
            && JITVariable::TYPE_VALUE !== $args[1]->type
            && JITVariable::TYPE_NULL !== $args[1]->type
            && !($args[1]->isNullConstant ?? false)) {
            throw new \LogicException('htmlentities() flags must be an integer in this compiler build');
        }

        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        $flagsKnown = $argc < 2;
        if ($argc >= 2) {
            // Null flags need runtime DEP / strict TypeError — do not fold (#31212).
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                $flagsKnown = false;
            } else {
                $flagsVal = self::compileTimeLong($context, $args[1]);
                if (null !== $flagsVal) {
                    $flags = $flagsVal;
                    $flagsKnown = true;
                }
            }
        }
        \PHPCompiler\JIT\Builtin\HtmlEntitiesJit::ensureLinked($context);
        $str = self::jitStringArg($context, $args[0], 0, 'string');
        $flagsLlvm = $context->getTypeFromString('int64')->constInt(ENT_QUOTES | ENT_SUBSTITUTE, false);
        if ($argc >= 2) {
            $flagsLlvm = $flagsKnown
                ? $context->getTypeFromString('int64')->constInt($flags, false)
                : JitIntdiv::lowerIntBuiltinArgForCaller(
                    $context,
                    $args[1],
                    'htmlentities',
                    2,
                    'flags'
                );
        }

        return JitHtmlentities::escape($context, $str, $flagsLlvm);
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function tryCompileTimeHtmlentities(Context $context, array $args): ?Value
    {
        $argc = \count($args);
        $literal = JitStringArg::compileTimeLiteral($args[0]);
        if (null === $literal || !self::isCompileTimeFoldableString($args[0])) {
            return null;
        }

        $flags = ENT_QUOTES | ENT_SUBSTITUTE;
        if ($argc >= 2) {
            if (JITVariable::TYPE_NULL === $args[1]->type || ($args[1]->isNullConstant ?? false)) {
                return null;
            }
            $flagsVal = self::compileTimeLong($context, $args[1]);
            if (null === $flagsVal) {
                return null;
            }
            $flags = $flagsVal;
        }

        $encoding = 'UTF-8';
        if ($argc >= 3 && !self::encodingArgIsNull($args[2])) {
            $encodingLit = JitStringArg::compileTimeLiteral($args[2]);
            // KIND_VARIABLE string slots keep compileTimeString (peer htmlspecialchars #25345).
            if (null === $encodingLit || !self::isCompileTimeFoldableString($args[2])) {
                return null;
            }
            $encoding = $encodingLit;
        }

        $doubleEncode = true;
        if ($argc >= 4) {
            if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
                return null;
            }
            $doubleEncodeVal = self::compileTimeBool($context, $args[3]);
            if (null === $doubleEncodeVal) {
                return null;
            }
            $doubleEncode = $doubleEncodeVal;
        }

        return $context->builder->load(
            $context->constantStringFromString(
                VmString::htmlentities($literal, $flags, $encoding, $doubleEncode)
            )
        );
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function assertJitUtf8Encoding(array $args, int $argc): void
    {
        if ($argc < 3 || self::encodingArgIsNull($args[2])) {
            return;
        }
        $encodingLit = JitStringArg::compileTimeLiteral($args[2]);
        if (null === $encodingLit) {
            throw new \LogicException(
                'htmlentities() JIT encoding must be a compile-time string in this compiler build'
            );
        }
        if (0 !== strcasecmp($encodingLit, 'UTF-8')) {
            throw new \LogicException(
                'htmlentities() JIT only supports UTF-8 encoding in this compiler build'
            );
        }
    }

    private static function compileTimeBool(Context $context, JITVariable $var): ?bool
    {
        if (null !== $var->compileTimeLong) {
            return 0 !== $var->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_BOOL === $var->type
            && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return 0 !== (int) $lib->LLVMConstIntGetZExtValue($var->value->value);
            }
        }
        $name = $var->compileTimeConstantName ?? null;
        if (null !== $name) {
            $lc = strtolower($name);
            if ('true' === $lc) {
                return true;
            }
            if ('false' === $lc) {
                return false;
            }
        }
        if (null !== $name && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($name);
            if (null !== $phpVar && Variable::TYPE_BOOLEAN === $phpVar->resolveIndirect()->type) {
                return $phpVar->resolveIndirect()->toBool();
            }
        }

        return null;
    }

    /**
     * Proven compile-time string for htmlentities fold (#26889 / peer #25345).
     */
    private static function isCompileTimeFoldableString(JITVariable $arg): bool
    {
        if (null === ($arg->compileTimeString ?? null)) {
            return false;
        }
        if (JITVariable::KIND_VALUE === $arg->kind) {
            return true;
        }

        return JITVariable::TYPE_STRING === $arg->type
            && JITVariable::KIND_VARIABLE === $arg->kind;
    }

    /** Zend 8.4 DEP+coerces null (not TypeError until 9.0); use soft-null path (#21405). */
    private static function vmStringArg(Frame $frame, int $argIndex, string $paramName): string
    {
        if (InternalStrictArg::isCallerStrict($frame)) {
            return InternalStrictArg::requireString($frame, $argIndex, 'htmlentities', $paramName)->toString();
        }

        return VmString::trimFamilyStringArgForFrame(
            $frame,
            $argIndex,
            'htmlentities',
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
                'htmlentities',
                $argIndex,
                $paramName
            );
        }

        return JitStringBuiltinArg::lowerTrimFamilyString(
            $context,
            $arg,
            'htmlentities',
            $argIndex,
            $paramName
        );
    }

    private static function compileTimeLong(Context $context, JITVariable $var): ?int
    {
        if (null !== $var->compileTimeLong) {
            return (int) $var->compileTimeLong;
        }
        if (JITVariable::TYPE_NATIVE_LONG === $var->type
            && JITVariable::KIND_VALUE === $var->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($var->value->value)) {
                return (int) $lib->LLVMConstIntGetSExtValue($var->value->value);
            }
        }
        if (null !== $var->compileTimeConstantName && null !== $context->runtime->vmContext) {
            $phpVar = $context->runtime->vmContext->constantFetch($var->compileTimeConstantName);
            if (null !== $phpVar && Variable::TYPE_INTEGER === $phpVar->resolveIndirect()->type) {
                return $phpVar->resolveIndirect()->toInt();
            }
        }

        return null;
    }

    private static function resolveEncodingVm(Variable $encVar): string
    {
        if (Variable::TYPE_NULL === $encVar->type) {
            return 'UTF-8';
        }
        if (Variable::TYPE_STRING !== $encVar->type) {
            throw new \TypeError(
                'htmlentities(): Argument #3 ($encoding) must be of type ?string, '
                .self::vmTypeName($encVar->type).' given'
            );
        }

        return $encVar->toString();
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
}
