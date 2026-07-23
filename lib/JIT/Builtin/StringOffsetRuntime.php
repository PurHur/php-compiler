<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\StringOffsetJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for string offset semantics via StringOffsetJitHelper PHP (#10245, #21497).
 *
 * Embed + thin standalone AOT: {@see StringOffsetJitHelper} via {@see JitVmHelperLink}
 * (ChunkSplit #21399 / ObOutput #21476 shape — no user-script standalone inline LLVM fork).
 * Nested helper compile leaf: inline LLVM matching {@see StringOffsetJitHelper::normalizeByteIndex}
 * without re-entering NestedJIT (#17279 / MathFpow Kernel shape).
 * SSOT: {@see \PHPCompiler\VM\StringOffsetJitHelper}, {@see \PHPCompiler\VM\Variable}
 * php-src: Zend/zend_operators.c — string offset fetch/write
 */
final class StringOffsetRuntime
{
    private const ABI_NORMALIZE = '__string_offset__normalize';

    private const HELPER_PATH = '/lib/VM/StringOffsetJitHelper.php';

    private const NORMALIZE_HELPER = 'PHPCompiler\\VM\\StringOffsetJitHelper::normalizeByteIndex';

    private const BYTE_FROM_LONG_HELPER = 'PHPCompiler\\VM\\StringOffsetJitHelper::byteFromLong';

    private const BYTE_FROM_STRING_HELPER = 'PHPCompiler\\VM\\StringOffsetJitHelper::byteFromStringFirstChar';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::NORMALIZE_HELPER,
        self::BYTE_FROM_LONG_HELPER,
        self::BYTE_FROM_STRING_HELPER,
    ];

    private const BRIDGE_ENTRY = 'string_offset_norm_bridge_entry';

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        // Nested helper compile of unrelated units that still need normalize:
        // thin LLVM leaf without re-entering StringOffsetJitHelper (#21497 / #17279).
        if (NestedJitCompileScope::isActive()) {
            self::implementNestedLeafNormalizeBridge($context);

            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_NORMALIZE);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            $context->registerFunction(self::ABI_NORMALIZE, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_NORMALIZE,
            self::BRIDGE_ENTRY,
            [$i64, $i64],
            $i64,
            self::NORMALIZE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21497'
        );
    }

    public static function emitIncDecError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, StringOffsetJitHelper::incDecErrorMessage());
    }

    public static function emitEmptyAssignError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, StringOffsetJitHelper::emptyAssignErrorMessage());
    }

    public static function emitRefError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, StringOffsetJitHelper::refErrorMessage());
    }

    public static function dimFetch(Context $context, Value $str, JitVariable $dim): Value
    {
        self::ensureLinked($context);
        $map = $context->structFieldMap['__string__'];
        $chars = $context->builder->structGep($str, $map['value']);
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        // `$s[$i]` accepts int, bool, null, float and numeric-string offsets; JitLongArg
        // applies those coercions and always hands back an i64. A raw loadValue() here
        // returned the `%__value__` struct for a box-backed offset (#22638).
        $index = JitLongArg::lower($context, $dim, 'string offset');
        $offset = self::normalizeOffset($context, $index, $len);

        return $context->builder->gep($chars, $offset);
    }

    public static function normalizeOffset(Context $context, Value $index, Value $len): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_NORMALIZE);
        $sizeT = $context->getTypeFromString('size_t');
        $normalized = $context->builder->call(
            $fn,
            self::offsetToI64($context, $index),
            self::offsetToI64($context, $len)
        );

        return $context->builder->truncOrBitCast($normalized, $sizeT);
    }

    /**
     * Coerce an offset/length operand to the i64 {@see ABI_NORMALIZE} declares.
     *
     * Callers hand over whatever `loadValue()` produced: a box-backed offset is a
     * `%__value__` struct, a native length is already i64. The blind `zext` this
     * replaces emitted `zext %__value__ ... to i64`, which fails module verification
     * — and since every JIT context builds the htmlspecialchars helper, and that
     * helper indexes a string, the one bad instruction poisoned every helper unit
     * and every user-script AOT module (#22638).
     */
    private static function offsetToI64(Context $context, Value $value): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $name = $context->getStringFromType($value->typeOf());
        if (1 === preg_match('/^int(\d+)$/', $name, $match)) {
            $width = (int) $match[1];
            if (64 === $width) {
                return $value;
            }

            return $width < 64
                ? $context->builder->zext($value, $i64)
                : $context->builder->truncOrBitCast($value, $i64);
        }
        if ('__value__' === $name || '__value__*' === $name) {
            return $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                '__value__*' === $name ? $value : self::spillValueBox($context, $value)
            );
        }

        throw new \LogicException("string offset operand must be an integer, got {$name}");
    }

    /** Address a `%__value__` operand that arrived by value rather than by pointer. */
    private static function spillValueBox(Context $context, Value $value): Value
    {
        $slot = $context->builder->alloca($value->typeOf(), 1, 'string_offset_box');
        $context->builder->store($value, $slot);

        return JitValueBox::pointer($context, $slot);
    }

    public static function dimAssign(Context $context, Value $charPtr, JitVariable $value): void
    {
        if (self::assignRhsIsEmptyAtCompileTime($value)) {
            self::emitEmptyAssignError($context);

            return;
        }
        $byte = self::assignByte($context, $value);
        $context->builder->store($byte, $charPtr);
    }

    public static function readAsString(Context $context, Value $charPtr): Value
    {
        $byte = $context->builder->load($charPtr);
        $i8 = $context->getTypeFromString('int8');
        $buf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(1));
        $bufChar = $context->builder->pointerCast($buf, $context->getTypeFromString('char*'));
        $context->builder->store($byte, $bufChar);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->getTypeFromString('int64')->constInt(1, false),
            $bufChar
        );
    }

    private static function assignRhsIsEmptyAtCompileTime(JitVariable $value): bool
    {
        if (JitVariable::TYPE_NULL === $value->type || $value->isNullConstant) {
            return true;
        }

        return '' === ($value->compileTimeString ?? null);
    }

    private static function assignByte(Context $context, JitVariable $value): Value
    {
        $i8 = $context->getTypeFromString('int8');
        // Nested leaf: avoid NestedJIT of byte helpers while another helper compiles (#21497).
        if (NestedJitCompileScope::isActive()) {
            switch ($value->type) {
                case JitVariable::TYPE_NATIVE_LONG:
                    return $context->builder->truncOrBitCast(
                        $context->helper->loadValue($value),
                        $i8
                    );
                case JitVariable::TYPE_STRING:
                    $str = $context->helper->loadValue($value);
                    $map = $context->structFieldMap['__string__'];
                    $chars = $context->builder->structGep($str, $map['value']);

                    return $context->builder->load($chars);
                default:
                    throw new \LogicException(
                        'String offset assignment supports int or string RHS in JIT (got type '.$value->type.')'
                    );
            }
        }

        self::ensureHelpersCompiled($context);
        switch ($value->type) {
            case JitVariable::TYPE_NATIVE_LONG:
                $long = $context->helper->loadValue($value);

                return $context->builder->truncOrBitCast(
                    $context->builder->call(
                        self::helperFunction($context, self::BYTE_FROM_LONG_HELPER),
                        $long
                    ),
                    $i8
                );
            case JitVariable::TYPE_STRING:
                $str = $context->helper->loadValue($value);
                $byteInt = $context->builder->call(
                    self::helperFunction($context, self::BYTE_FROM_STRING_HELPER),
                    $str
                );

                return $context->builder->truncOrBitCast($byteInt, $i8);
            default:
                throw new \LogicException(
                    'String offset assignment supports int or string RHS in JIT (got type '.$value->type.')'
                );
        }
    }

    /**
     * NestedJIT leaf only — mirrors {@see StringOffsetJitHelper::normalizeByteIndex} in LLVM.
     * Not used for user-script / thin standalone AOT (those take {@see JitVmHelperLink::ensureBridge}).
     */
    private static function implementNestedLeafNormalizeBridge(Context $context): void
    {
        $abiName = self::ABI_NORMALIZE;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('string_offset_norm_nested_leaf_entry');
        $negBlock = $fn->appendBasicBlock('string_offset_norm_nested_leaf_neg');
        $posBlock = $fn->appendBasicBlock('string_offset_norm_nested_leaf_pos');
        $doneBlock = $fn->appendBasicBlock('string_offset_norm_nested_leaf_done');
        $context->builder->positionAtEnd($entry);
        $index = $fn->getParam(0);
        $len = $fn->getParam(1);
        $zero = $i64->constInt(0, false);
        $isNegative = $context->builder->icmp(Builder::INT_SLT, $index, $zero);
        $context->builder->branchIf($isNegative, $negBlock, $posBlock);

        $context->builder->positionAtEnd($negBlock);
        $adjusted = $context->builder->add($len, $index);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($i64);
        $phi->addIncoming($adjusted, $negBlock);
        $phi->addIncoming($index, $posBlock);
        $context->builder->returnValue($phi);
        $context->registerFunction($abiName, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureHelpersCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StringOffsetJitHelper compile (#21497)');
        }

        return $fn;
    }

    private static function ensureHelpersCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21497'
        );
    }
}
