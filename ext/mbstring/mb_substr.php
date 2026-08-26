<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbStrcut;
use PHPCompiler\JIT\Builtin\MbSubstr;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStrictIntArg;
use PHPCompiler\JIT\JitStringArg;
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
 * JIT/AOT: {@see JitMbSubstr} → NestedJIT {@see MbSubstrJitHelper} (#27028 / #34256).
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
 * LLVM lowering for mb_substr() — MbSubstrJitHelper NestedJIT (#27028 / #34256 / #34875).
 *
 * Co-located with {@see mb_substr} so composer autoload does not invent a new inventory unit.
 * Runtime int offsets must go through {@see JitNestedHelperCoerce::callHelper} (raw call SIGSEGVs).
 * Runtime encoding via NestedJIT assertEncodingArgv (#34875 leftover of #34256).
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
        $encLit = self::compileTimeEncoding($args, $argc);
        $lengthFold = self::compileTimeLengthFold($context, $args, $argc);
        // Only fold when encoding is a supported canon — invalid names must reach NestedJIT
        // for catchable ValueError (peer JitMbSearch #34866; #34875).
        if (
            null !== $strLit
            && null !== $startLit
            && null !== $lengthFold
            && null !== $encLit
            && self::isSupportedEncoding($encLit)
        ) {
            return self::materializeString(
                $context,
                VmMbstring::substr($strLit, $startLit, $lengthFold['value'], $encLit)
            );
        }

        // Soft-null DEP+coerce on 8.4 (php-src mbstring.c; #21197).
        $str = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], 'mb_substr', 0, 'string');
        $start = JitStrictIntArg::lower($context, $args[1], 'mb_substr', 2, 'start');
        $i64 = $context->getTypeFromString('int64');
        // 4-arg ABI: length=-1 omitted. Extra hasLength int breaks NestedJIT length ABI (#34256).
        if ($argc >= 3) {
            if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
                $length = $i64->constInt(-1, true);
            } else {
                $length = JitStrictIntArg::lower($context, $args[2], 'mb_substr', 3, 'length');
            }
        } else {
            $length = $i64->constInt(-1, true);
        }

        $encPtr = self::linkAndEncodingPtr($context, $args, $argc, 'mb_substr');
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbSubstr::helperFunction($context),
            [$str, $start, $length, $encPtr]
        );
        $resultStr = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw);
        $owned = $context->builder->call($context->lookupFunction('__string__separate'), $resultStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $owned);

        return $ptr;
    }

    /**
     * Link NestedJIT substr helpers, lower encoding (literal or runtime), assert when needed (#34875).
     *
     * @param list<JITVariable> $args
     */
    private static function linkAndEncodingPtr(Context $context, array $args, int $argc, string $function): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbSubstr::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, $function.'_runtime');

        [$encPtr, $needsAssert] = self::encodingPtr($context, $args, $argc, $function);
        if ($needsAssert) {
            $fnName = $context->builder->load($context->constantStringFromString($function));
            $context->builder->call(
                MbSubstr::assertEncodingHelper($context),
                $encPtr,
                $fnName
            );
        }

        return $encPtr;
    }

    /**
     * Literal UTF-8/ASCII/8BIT → constant string (no assert); otherwise NestedJIT encoding + assert (#34875).
     *
     * @param list<JITVariable> $args
     * @return array{0: Value, 1: bool} encoding ptr, needsAssert
     */
    private static function encodingPtr(Context $context, array $args, int $argc, string $function): array
    {
        if ($argc < 4 || JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
            $encoding = MbstringAotFoldState::internalEncoding($context) ?? MbstringState::internalEncoding();
            if (!self::isSupportedEncoding($encoding)) {
                $encoding = 'UTF-8';
            }

            return [$context->builder->load($context->constantStringFromString($encoding)), false];
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[3]);
        if (null !== $encodingLit) {
            $canonical = MbstringEncodingRegistry::resolve($encodingLit);
            if (null !== $canonical && self::isSupportedEncoding($canonical)) {
                return [$context->builder->load($context->constantStringFromString($canonical)), false];
            }

            return [$context->builder->load($context->constantStringFromString($encodingLit)), true];
        }

        return [
            JitStringBuiltinArg::lower(
                $context,
                $args[3],
                $function,
                3,
                'encoding'
            ),
            true,
        ];
    }

    /**
     * @param list<JITVariable> $args
     */
    private static function compileTimeEncoding(array $args, int $argc): ?string
    {
        if ($argc < 4) {
            return MbstringState::internalEncoding();
        }
        if (JITVariable::TYPE_NULL === $args[3]->type || ($args[3]->isNullConstant ?? false)) {
            return MbstringState::internalEncoding();
        }
        $lit = JitStringArg::compileTimeLiteral($args[3]);
        if (null === $lit) {
            return null;
        }
        $canonical = MbstringEncodingRegistry::resolve($lit);

        return null !== $canonical ? $canonical : $lit;
    }

    private static function isSupportedEncoding(string $encoding): bool
    {
        return 'UTF-8' === $encoding || 'ASCII' === $encoding || '8BIT' === $encoding;
    }

    /**
     * @return array{value: ?int}|null null when length is not foldable
     */
    private static function compileTimeLengthFold(Context $context, array $args, int $argc): ?array
    {
        if ($argc < 3) {
            return ['value' => null];
        }
        if (JITVariable::TYPE_NULL === $args[2]->type || ($args[2]->isNullConstant ?? false)) {
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
