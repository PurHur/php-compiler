<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNativeString;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\StringOffsetJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for string offset semantics via StringOffsetJitHelper PHP (#10245).
 *
 * SSOT: {@see \PHPCompiler\VM\StringOffsetJitHelper}, {@see \PHPCompiler\VM\Variable}
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

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NORMALIZE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::implementStandaloneNormalizeBridge($context);
        } else {
            self::ensureJitHelperCompiled($context);
            self::implementEmbedNormalizeBridge($context);
        }
        $context->builder->clearInsertionPosition();
    }

    public static function emitIncDecError(Context $context): void
    {
        ErrorRaise::registerDeclarations($context);
        ErrorRaise::ensureLinked($context);
        ErrorRaise::emitRaise($context, StringOffsetJitHelper::incDecErrorMessage());
    }

    public static function dimFetch(Context $context, Value $strSlot, JitVariable $dim): Value
    {
        self::ensureLinked($context);
        $str = $context->builder->load($strSlot);
        $map = $context->structFieldMap['__string__'];
        $chars = $context->builder->structGep($str, $map['value']);
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $index = $context->helper->loadValue($dim);
        $offset = self::normalizeOffset($context, $index, $len);

        return $context->builder->gep($chars, $offset);
    }

    public static function normalizeOffset(Context $context, Value $index, Value $len): Value
    {
        self::ensureLinked($context);
        JitNativeString::ensureInsertBlock($context);
        $fn = $context->lookupFunction(self::ABI_NORMALIZE);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $normalized = $context->builder->call(
            $fn,
            $context->builder->zext($index, $i64),
            $context->builder->zext($len, $i64)
        );

        return $context->builder->truncOrBitCast($normalized, $sizeT);
    }

    public static function dimAssign(Context $context, Value $charPtr, JitVariable $value): void
    {
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

    private static function assignByte(Context $context, JitVariable $value): Value
    {
        $i8 = $context->getTypeFromString('int8');
        switch ($value->type) {
            case JitVariable::TYPE_NATIVE_LONG:
                $long = $context->helper->loadValue($value);
                if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                    return $context->builder->truncOrBitCast($long, $i8);
                }
                self::ensureLinked($context);

                return $context->builder->truncOrBitCast(
                    $context->builder->call(
                        self::helperFunction($context, self::BYTE_FROM_LONG_HELPER),
                        $long
                    ),
                    $i8
                );
            case JitVariable::TYPE_STRING:
                $str = $context->helper->loadValue($value);
                if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                    $map = $context->structFieldMap['__string__'];
                    $chars = $context->builder->structGep($str, $map['value']);

                    return $context->builder->load($chars);
                }
                self::ensureLinked($context);
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

    private static function implementEmbedNormalizeBridge(Context $context): void
    {
        $abiName = self::ABI_NORMALIZE;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('string_offset_norm_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->returnValue(
            $context->builder->call(
                self::helperFunction($context, self::NORMALIZE_HELPER),
                $fn->getParam(0),
                $fn->getParam(1)
            )
        );
        $context->registerFunction($abiName, $fn);
    }

    /** Standalone AOT: inline LLVM matching StringOffsetJitHelper PHP SSOT (#10245). */
    private static function implementStandaloneNormalizeBridge(Context $context): void
    {
        $abiName = self::ABI_NORMALIZE;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($i64, false, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('string_offset_norm_entry');
        $negBlock = $fn->appendBasicBlock('string_offset_norm_neg');
        $posBlock = $fn->appendBasicBlock('string_offset_norm_pos');
        $doneBlock = $fn->appendBasicBlock('string_offset_norm_done');
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
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StringOffsetJitHelper compile (#10245)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StringOffsetJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StringOffsetJitHelper.php parseAndCompile failed (#10245)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#10245)');
            }
        }
    }
}
