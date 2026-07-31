<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\StringOffsetJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Type;
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

    public static function emitAssignOpError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, StringOffsetJitHelper::assignOpErrorMessage());
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
        $index = $context->helper->loadValue($dim);
        $offset = self::normalizeOffset($context, $index, $len);

        return $context->builder->gep($chars, $offset);
    }

    /**
     * Bounds-checked string offset read → length-1 (or empty) {@see __string__*} (#22646).
     *
     * LLVM bounds check (mirrors {@see StringOffsetJitHelper::readOffset}); NestedJIT of
     * trigger_error inside that helper is too heavy for every dim-fetch module.
     */
    public static function readDimAsString(Context $context, Value $str, JitVariable $dim): Value
    {
        self::ensureLinked($context);

        return self::readDimAsStringNestedLeaf($context, $str, $dim);
    }

    /**
     * Bounds-safe read + E_WARNING on OOR (#22646 / Zend uninitialized string offset).
     */
    private static function readDimAsStringNestedLeaf(Context $context, Value $str, JitVariable $dim): Value
    {
        $map = $context->structFieldMap['__string__'];
        $chars = $context->builder->structGep($str, $map['value']);
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $rawIndex = self::coerceOffsetOperandToI64($context, $context->helper->loadValue($dim));
        $offset = self::normalizeOffset($context, $rawIndex, $len);
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGE, $offset, $zero),
            $context->builder->icmp(Builder::INT_ULT, $offset, $len)
        );
        $okBlock = BasicBlockHelper::append($context, 'str_off_read_ok');
        $oobBlock = BasicBlockHelper::append($context, 'str_off_read_oob');
        $doneBlock = BasicBlockHelper::append($context, 'str_off_read_done');
        $context->builder->branchIf($inRange, $okBlock, $oobBlock);

        $context->builder->positionAtEnd($okBlock);
        $charPtr = $context->builder->gep($chars, $offset);
        $okStr = self::readAsString($context, $charPtr);
        $okEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($oobBlock);
        self::emitUninitializedOffsetWarning($context, $dim);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->getTypeFromString('int64')->constInt(0, false),
            $context->getTypeFromString('char*')->constNull()
        );
        $oobEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $phi->addIncoming($okStr, $okEnd);
        $phi->addIncoming($empty, $oobEnd);

        return $phi;
    }

    /** E_WARNING for OOR string offset via {@see __compiler_trigger_error} (#22646). */
    private static function emitUninitializedOffsetWarning(Context $context, JitVariable $dim): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerError::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $message = 'Uninitialized string offset';
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(\PHPCompiler\VM\ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    public static function normalizeOffset(Context $context, Value $index, Value $len): Value
    {
        self::ensureLinked($context);
        $fn = $context->lookupFunction(self::ABI_NORMALIZE);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        // Box-backed dims arrive as %__value__ — never blind-zext a struct (#22638).
        $normalized = $context->builder->call(
            $fn,
            self::coerceOffsetOperandToI64($context, $index),
            self::coerceOffsetOperandToI64($context, $len)
        );

        return $context->builder->truncOrBitCast($normalized, $sizeT);
    }

    /** Widen integer offset/length operands to i64; extract from __value__ boxes first. */
    private static function coerceOffsetOperandToI64(Context $context, Value $operand): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $ty = $operand->typeOf();
        if ($ty === $i64) {
            return $operand;
        }
        if (JitNestedHelperCoerce::isValueBoxType($context, $ty)) {
            return JitNestedHelperCoerce::extractLongFromHelperResult($context, $operand, $i64);
        }
        if (Type::KIND_INTEGER === $ty->getKind()) {
            return $context->builder->zext($operand, $i64);
        }

        return JitNestedHelperCoerce::extractLongFromHelperResult($context, $operand, $i64);
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
                case JitVariable::TYPE_HASHTABLE:
                    // Nested leaf: skip user-visible warnings (same as OOR path).
                    return $i8->constInt(\ord('A'), false);
                case JitVariable::TYPE_OBJECT:
                    return self::assignByteFromObject($context, $value, true);
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
            case JitVariable::TYPE_HASHTABLE:
                // Zend: Array→string warning then first-byte warning → 'A' (#22925).
                self::emitAssignEwarning($context, StringOffsetJitHelper::ARRAY_TO_STRING_WARNING);
                self::emitAssignEwarning($context, StringOffsetJitHelper::FIRST_BYTE_WARNING);

                return $i8->constInt(\ord('A'), false);
            case JitVariable::TYPE_OBJECT:
                // Zend convert_to_string → __toString first byte; Error without (#25794).
                return self::assignByteFromObject($context, $value, false);
            default:
                throw new \LogicException(
                    'String offset assignment supports int or string RHS in JIT (got type '.$value->type.')'
                );
        }
    }

    /**
     * Object RHS: coerce via __toString then first byte (Zend zend_assign_to_string_offset, #25794).
     */
    private static function assignByteFromObject(Context $context, JitVariable $value, bool $nestedLeaf): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $asString = \PHPCompiler\JIT\MagicMethodDispatch::coerceObjectToString($context, $value);
        if (null === $asString) {
            $classHint = $value->type?->userType ?? '';
            $classHint = \ltrim((string) $classHint, '\\');
            if ('' === $classHint || 'object' === \strtolower($classHint)) {
                $classHint = 'stdClass';
            }
            ErrorRaise::registerDeclarations($context);
            ErrorRaise::ensureLinked($context);
            ErrorRaise::emitRaise(
                $context,
                StringOffsetJitHelper::objectToStringErrorMessage($classHint)
            );

            return $i8->constInt(0, false);
        }
        if (!$nestedLeaf) {
            // Multi-byte __toString result → first-byte warning (same as string RHS).
            $strVal = $context->helper->loadValue($asString);
            $map = $context->structFieldMap['__string__'];
            $len = $context->builder->load($context->builder->structGep($strVal, $map['length']));
            $i64 = $context->getTypeFromString('int64');
            $isMulti = $context->builder->icmp(
                Builder::INT_SGT,
                $len,
                $i64->constInt(1, false)
            );
            $warnBlock = BasicBlockHelper::append($context, 'soff_obj_mb_warn');
            $contBlock = BasicBlockHelper::append($context, 'soff_obj_mb_cont');
            $context->builder->branchIf($isMulti, $warnBlock, $contBlock);
            $context->builder->positionAtEnd($warnBlock);
            self::emitAssignEwarning($context, StringOffsetJitHelper::FIRST_BYTE_WARNING);
            $context->builder->branch($contBlock);
            $context->builder->positionAtEnd($contBlock);
        }
        $str = $context->helper->loadValue($asString);
        $map = $context->structFieldMap['__string__'];
        $chars = $context->builder->structGep($str, $map['value']);

        return $context->builder->load($chars);
    }

    /** E_WARNING during string-offset assign (Array→string / first-byte, #22925). */
    private static function emitAssignEwarning(Context $context, string $message): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerError::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(\PHPCompiler\VM\ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
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
