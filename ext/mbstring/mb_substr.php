<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\MbStrcut;
use PHPCompiler\JIT\Builtin\MbSubstr;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * mb_substr() — multibyte substring (php-src ext/mbstring/mbstring.c; #3239, #27028).
 *
 * php-src mbstring.stub.php arity 4 — no user-facing $truncate (#23603).
 * JIT/AOT: {@see JitMbSubstr} → NestedJIT {@see MbSubstrJitHelper} (#27028).
 */
final class mb_substr extends Internal
{
    public function __construct()
    {
        parent::__construct('mb_substr');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(\sprintf(
                'mb_substr() expects at least 2 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 4) {
            throw new \ArgumentCountError(\sprintf(
                'mb_substr() expects at most 4 arguments, %d given',
                $argc
            ));
        }
        // Z_PARAM_STR $string — non-strict null is E_DEPRECATED + '' on 8.4 (php-src mbstring.c / #21197).
        $string = VmString::trimFamilyStringArgForFrame($frame, 0, 'mb_substr', 0, 'string');
        if (null === $frame->returnVar) {
            return;
        }
        $start = VmMbstring::coerceStartArg($frame, 'mb_substr', 1);
        $length = null;
        if (isset($frame->calledArgs[2])) {
            $length = VmMbstring::coerceOptionalLengthArg($frame, 'mb_substr', 2);
        }
        $encoding = isset($frame->calledArgs[3])
            ? VmMbstring::coerceEncodingArg($frame->calledArgs[3], 'mb_substr', 3)
            : 'UTF-8';
        // No Z_STR_TRUNCATED / clip E_WARNING in php-src mbstring (#22489, #28556, #27749).
        BuiltinExecute::writeReturn(
            $frame,
            static fn (Variable $ret) => $ret->string(
                VmMbstring::substr($string, $start, $length, $encoding, false, $frame)
            )
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitMbSubstr::invoke($context, ...$args);
    }
}

/**
 * LLVM lowering for mb_substr() — MbSubstrJitHelper NestedJIT (#27028).
 *
 * Co-located with {@see mb_substr} so composer autoload does not invent a new inventory unit.
 */
final class JitMbSubstr
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 4) {
            throw new \LogicException('mb_substr() expects 2 to 4 arguments in this compiler build');
        }

        // MbSubstr is co-located in MbStrcut.php — load via primary classmap entry.
        \class_exists(MbStrcut::class);

        $strLit = $args[0]->compileTimeString ?? null;
        $startLit = self::compileTimeInt($context, $args[1]);
        $encLit = $argc >= 4 ? ($args[3]->compileTimeString ?? null) : 'UTF-8';
        $lengthFold = self::compileTimeLengthFold($context, $args, $argc);
        if (null !== $strLit && null !== $startLit && null !== $lengthFold && null !== $encLit) {
            return self::materializeString(
                $context,
                VmMbstring::substr($strLit, $startLit, $lengthFold['value'], $encLit)
            );
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21197).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_substr', 0, 'string');
        $start = JitStrictIntArg::lower($context, $args[1], 'mb_substr', 2, 'start');
        $i64 = $context->getTypeFromString('int64');
        if ($argc >= 3) {
            if (JITVariable::TYPE_NULL === $args[2]->type) {
                $length = $i64->constInt(0, true);
                $hasLength = $i64->constInt(0, false);
            } else {
                $length = JitStrictIntArg::lower($context, $args[2], 'mb_substr', 3, 'length');
                $hasLength = $i64->constInt(1, false);
            }
        } else {
            $length = $i64->constInt(0, true);
            $hasLength = $i64->constInt(0, false);
        }
        if ($argc >= 4) {
            if (JITVariable::TYPE_STRING !== $args[3]->type) {
                throw new \LogicException('mb_substr() encoding must be a string literal in this compiler build');
            }
            $encoding = $args[3]->compileTimeString ?? 'UTF-8';
        } else {
            $encoding = 'UTF-8';
        }
        if ('UTF-8' !== $encoding && 'ASCII' !== $encoding && '8BIT' !== $encoding) {
            throw new \LogicException(
                'mb_substr() JIT only supports UTF-8, ASCII, or 8BIT encoding literals in this compiler build'
            );
        }

        MbSubstr::ensureLinked($context);
        $encPtr = $context->builder->load($context->constantStringFromString($encoding));
        $resultStr = $context->builder->call(
            MbSubstr::helperFunction($context),
            $str,
            $start,
            $length,
            $hasLength,
            $encPtr
        );
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    /**
     * @return array{value: ?int}|null null when length is not foldable
     */
    private static function compileTimeLengthFold(Context $context, array $args, int $argc): ?array
    {
        if ($argc < 3) {
            return ['value' => null];
        }
        if (JITVariable::TYPE_NULL === $args[2]->type) {
            return ['value' => null];
        }
        $len = self::compileTimeInt($context, $args[2]);
        if (null === $len) {
            return null;
        }

        return ['value' => $len];
    }

    private static function materializeString(Context $context, string $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($str))
        );
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    private static function compileTimeInt(Context $context, JITVariable $arg): ?int
    {
        if (JITVariable::TYPE_NATIVE_LONG !== $arg->type || JITVariable::KIND_VALUE !== $arg->kind) {
            return null;
        }
        $lib = $context->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($arg->value->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetSExtValue($arg->value->value);
    }
}
