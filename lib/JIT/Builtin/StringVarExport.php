<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_var_export via VarExportJitHelper PHP (#9189, #13349, #19430, #20589).
 *
 * Embed: NestedJIT {@see VarExportJitHelper} (php-in-PHP).
 * Thin standalone AOT: scalar LLVM bridge — NestedJIT of the helper segfaults without
 * Runtime->vm (peer StringPrintR #24266 / StringVarDump #23540; #26855).
 * String scalars use pure-LLVM quote/escape (not NestedJIT addslashes — #27574).
 * SSOT: {@see \PHPCompiler\ext\standard\VmVarExport::formatVariable()}.
 * php-src: ext/standard/var.c — php_var_export_ex
 */
final class StringVarExport
{
    private const HELPER_PATH = '/ext/standard/VarExportJitHelper.php';

    private const FORMAT_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\VarExportJitHelper::formatValue';

    private const BRIDGE_ENTRY = 'var_export_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_VALUE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_var_export',
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
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_var_export');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        if ($context->isThinStandaloneAotMain()) {
            self::implementThinScalarBridge($context);
            self::registerLinkedRuntime($context);
            if (null !== $savedInsert) {
                BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            } else {
                $context->builder->clearInsertionPosition();
            }

            return;
        }

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $probe = $context->module->getNamedFunction('__compiler_var_export');
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);
            if (null !== $savedInsert) {
                BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            } else {
                $context->builder->clearInsertionPosition();
            }

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            if (null !== $savedInsert) {
                BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            } else {
                $context->builder->clearInsertionPosition();
            }

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_var_export',
            self::BRIDGE_ENTRY,
            [$valuePtr],
            $strPtr,
            self::FORMAT_VALUE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20589'
        );
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Thin standalone AOT: format scalars like Zend var_export without NestedJIT (#26855).
     */
    private static function implementThinScalarBridge(Context $context): void
    {
        $abiName = '__compiler_var_export';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        StringDir::ensureLinked($context);
        ZendDoubleStringRuntime::ensureLinked($context);
        ObOutputRuntime::ensureLinked($context);
        ValueEchoRuntime::ensureLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $valuePtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('var_export_thin_scalar_entry');
        $boolBlock = $fn->appendBasicBlock('var_export_thin_bool');
        $longBlock = $fn->appendBasicBlock('var_export_thin_long');
        $doubleBlock = $fn->appendBasicBlock('var_export_thin_double');
        $nullBlock = $fn->appendBasicBlock('var_export_thin_null');
        $stringBlock = $fn->appendBasicBlock('var_export_thin_string');
        $fallback = $fn->appendBasicBlock('var_export_thin_fallback');
        $done = $fn->appendBasicBlock('var_export_thin_done');

        $context->builder->positionAtEnd($entry);
        $arg = $fn->getParam(0);
        $nullPtrBlock = $fn->appendBasicBlock('var_export_thin_null_ptr');
        $havePtr = $fn->appendBasicBlock('var_export_thin_have_ptr');
        $isNullPtr = $context->builder->icmp(Builder::INT_EQ, $arg, $valuePtr->constNull());
        $context->builder->branchIf($isNullPtr, $nullPtrBlock, $havePtr);
        $context->builder->positionAtEnd($nullPtrBlock);
        $nullPtrStr = self::constExportString($context, 'NULL');
        $nullPtrEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);
        $context->builder->positionAtEnd($havePtr);

        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($arg, $map['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $isBool = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(JitVariable::TYPE_NATIVE_BOOL, false));
        $afterBool = $fn->appendBasicBlock('var_export_thin_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $arg);
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $trueBlock = $fn->appendBasicBlock('var_export_thin_bool_true');
        $falseBlock = $fn->appendBasicBlock('var_export_thin_bool_false');
        $boolDone = $fn->appendBasicBlock('var_export_thin_bool_done');
        $context->builder->branchIf($isTrue, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        $trueStr = self::constExportString($context, 'true');
        $trueEnd = $context->builder->getInsertBlock();
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($falseBlock);
        $falseStr = self::constExportString($context, 'false');
        $falseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($boolDone);
        $boolPhi = $context->builder->phi($strPtr);
        $boolPhi->addIncoming($trueStr, $trueEnd);
        $boolPhi->addIncoming($falseStr, $falseEnd);
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(JitVariable::TYPE_NATIVE_LONG, false));
        $afterLong = $fn->appendBasicBlock('var_export_thin_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $arg);
        $intMin = $i64->constInt(\PHP_INT_MIN, true);
        $isIntMin = $context->builder->icmp(Builder::INT_EQ, $longVal, $intMin);
        $intMinBlock = $fn->appendBasicBlock('var_export_thin_int_min');
        $intNormalBlock = $fn->appendBasicBlock('var_export_thin_int_normal');
        $intDone = $fn->appendBasicBlock('var_export_thin_int_done');
        $context->builder->branchIf($isIntMin, $intMinBlock, $intNormalBlock);
        $context->builder->positionAtEnd($intMinBlock);
        $intMinStr = self::constExportString($context, (string) (\PHP_INT_MIN + 1).'-1');
        $intMinEnd = $context->builder->getInsertBlock();
        $context->builder->branch($intDone);
        $context->builder->positionAtEnd($intNormalBlock);
        $intNormalStr = VmResourceIdString::formatBoxedNativeLong($context, $longVal);
        $intNormalEnd = $context->builder->getInsertBlock();
        $context->builder->branch($intDone);
        $context->builder->positionAtEnd($intDone);
        $longStr = $context->builder->phi($strPtr);
        $longStr->addIncoming($intMinStr, $intMinEnd);
        $longStr->addIncoming($intNormalStr, $intNormalEnd);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(JitVariable::TYPE_NATIVE_DOUBLE, false));
        $afterDouble = $fn->appendBasicBlock('var_export_thin_after_double');
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $arg);
        $doubleStr = ZendDoubleStringRuntime::format($context, $doubleVal);
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterDouble);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(JitVariable::TYPE_NULL, false));
        $afterNull = $fn->appendBasicBlock('var_export_thin_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        $nullStr = self::constExportString($context, 'NULL');
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterNull);
        // TYPE_STRING carries IS_REFCOUNTED; $kind is already masked with 0x7f above.
        $isString = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(JitVariable::TYPE_STRING & 0x7f, false));
        $context->builder->branchIf($isString, $stringBlock, $fallback);

        $context->builder->positionAtEnd($stringBlock);
        // Thin AOT must not call NestedJIT __string__addslashes — it segfaults without
        // Runtime->vm (#27574; peer NestedJIT avoid for VarExportJitHelper #26855).
        // Escape \\ / ' (+ NUL concat form) in pure LLVM — VmVarExport::formatExportString.
        $rawStr = $context->builder->call($context->lookupFunction('__value__readString'), $arg);
        $stringStr = self::formatExportQuotedString($context, $rawStr);
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($fallback);
        self::emitThinUnsupportedAbort($context);
        $context->builder->returnValue(self::constExportString($context, 'NULL'));

        $context->builder->positionAtEnd($done);
        $result = $context->builder->phi($strPtr);
        $result->addIncoming($nullPtrStr, $nullPtrEnd);
        $result->addIncoming($boolPhi, $boolEnd);
        $result->addIncoming($longStr, $longEnd);
        $result->addIncoming($doubleStr, $doubleEnd);
        $result->addIncoming($nullStr, $nullEnd);
        $result->addIncoming($stringStr, $stringEnd);
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function constExportString(Context $context, string $text): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $context->builder->pointerCast($context->constantFromString($text), $i8p)
        );
    }

    /**
     * Thin AOT: format a string like {@see \PHPCompiler\ext\standard\VmVarExport} formatExportString.
     *
     * Avoids NestedJIT {@see StringAddslashes} which segfaults under thin standalone (#27574).
     */
    private static function formatExportQuotedString(Context $context, Value $rawStr): Value
    {
        self::ensureFormatExportStringHelper($context);

        return $context->builder->call(
            $context->lookupFunction('__compiler_var_export_format_string'),
            $rawStr
        );
    }

    /**
     * Pure-LLVM helper: "'" + escape(\,') + NUL→' . "\0" . ' + "'".
     * Matches {@see \PHPCompiler\ext\standard\VmVarExport} formatExportString (#27574).
     */
    private static function ensureFormatExportStringHelper(Context $context): void
    {
        $name = '__compiler_var_export_format_string';
        $existing = $context->module->getNamedFunction($name);
        if (null !== $existing && $existing->countBasicBlocks() > 0) {
            $context->registerFunction($name, $existing);

            return;
        }

        StringDir::ensureLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $map = $context->structFieldMap['__string__'];
        $nulForm = "' . \"\\0\" . '";
        $nulFormLen = \strlen($nulForm);

        $fn = null !== $existing
            ? $existing
            : $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr)
            );

        $savedBuilder = $context->builder;
        $context->builder = $context->context->builderCreate();
        $b = $context->builder;

        $entry = $fn->appendBasicBlock('ve_fmt_entry');
        $nullIn = $fn->appendBasicBlock('ve_fmt_null');
        $countInit = $fn->appendBasicBlock('ve_fmt_count_init');
        $countLoop = $fn->appendBasicBlock('ve_fmt_count_loop');
        $countBody = $fn->appendBasicBlock('ve_fmt_count_body');
        $countNul = $fn->appendBasicBlock('ve_fmt_count_nul');
        $countEscCheck = $fn->appendBasicBlock('ve_fmt_count_esc_check');
        $countEsc = $fn->appendBasicBlock('ve_fmt_count_esc');
        $countLit = $fn->appendBasicBlock('ve_fmt_count_lit');
        $countNext = $fn->appendBasicBlock('ve_fmt_count_next');
        $countDone = $fn->appendBasicBlock('ve_fmt_count_done');
        $writeLoop = $fn->appendBasicBlock('ve_fmt_write_loop');
        $writeBody = $fn->appendBasicBlock('ve_fmt_write_body');
        $writeNul = $fn->appendBasicBlock('ve_fmt_write_nul');
        $writeEscCheck = $fn->appendBasicBlock('ve_fmt_write_esc_check');
        $writeEsc = $fn->appendBasicBlock('ve_fmt_write_esc');
        $writeLit = $fn->appendBasicBlock('ve_fmt_write_lit');
        $writeNext = $fn->appendBasicBlock('ve_fmt_write_next');
        $writeDone = $fn->appendBasicBlock('ve_fmt_write_done');

        $b->positionAtEnd($entry);
        $arg = $fn->getParam(0);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $arg, $strPtr->constNull()),
            $nullIn,
            $countInit
        );

        $b->positionAtEnd($nullIn);
        $b->returnValue($b->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(2, false),
            $b->pointerCast($context->constantFromString("''"), $i8p)
        ));

        $b->positionAtEnd($countInit);
        $inLen = $b->load($b->structGep($arg, $map['length']));
        $inChars = $b->pointerCast($b->structGep($arg, $map['value']), $i8p);
        $idx = $b->alloca($i64);
        $outLenSlot = $b->alloca($i64);
        $b->store($i64->constInt(0, false), $idx);
        $b->store($i64->constInt(2, false), $outLenSlot); // surrounding quotes
        $b->branch($countLoop);

        $b->positionAtEnd($countLoop);
        $ci = $b->load($idx);
        $b->branchIf($b->icmp(Builder::INT_ULT, $ci, $inLen), $countBody, $countDone);

        $b->positionAtEnd($countBody);
        $ch = $b->load($b->gep($inChars, $ci));
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false)),
            $countNul,
            $countEscCheck
        );

        $b->positionAtEnd($countNul);
        $b->store($b->add($b->load($outLenSlot), $i64->constInt($nulFormLen, false)), $outLenSlot);
        $b->branch($countNext);

        $b->positionAtEnd($countEscCheck);
        $needsEsc = $b->or(
            $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(\ord('\\'), false)),
            $b->icmp(Builder::INT_EQ, $ch, $i8->constInt(\ord("'"), false))
        );
        $b->branchIf($needsEsc, $countEsc, $countLit);

        $b->positionAtEnd($countEsc);
        $b->store($b->add($b->load($outLenSlot), $i64->constInt(2, false)), $outLenSlot);
        $b->branch($countNext);

        $b->positionAtEnd($countLit);
        $b->store($b->add($b->load($outLenSlot), $i64->constInt(1, false)), $outLenSlot);
        $b->branch($countNext);

        $b->positionAtEnd($countNext);
        $b->store($b->add($ci, $i64->constInt(1, false)), $idx);
        $b->branch($countLoop);

        $b->positionAtEnd($countDone);
        $total = $b->load($outLenSlot);
        $result = $b->call($context->lookupFunction('__string__alloc'), $total);
        $outChars = $b->pointerCast($b->structGep($result, $map['value']), $i8p);
        $b->store($i8->constInt(\ord("'"), false), $outChars);
        $outIdx = $b->alloca($i64);
        $b->store($i64->constInt(1, false), $outIdx);
        $b->store($i64->constInt(0, false), $idx);
        $b->branch($writeLoop);

        $b->positionAtEnd($writeLoop);
        $wi = $b->load($idx);
        $b->branchIf($b->icmp(Builder::INT_ULT, $wi, $inLen), $writeBody, $writeDone);

        $b->positionAtEnd($writeBody);
        $wch = $b->load($b->gep($inChars, $wi));
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $wch, $i8->constInt(0, false)),
            $writeNul,
            $writeEscCheck
        );

        $b->positionAtEnd($writeNul);
        $oiNul = $b->load($outIdx);
        $context->intrinsic->builder = $b;
        $context->intrinsic->memcpy(
            $b->gep($outChars, $oiNul),
            $b->pointerCast($context->constantFromString($nulForm), $i8p),
            $i64->constInt($nulFormLen, false),
            false
        );
        $b->store($b->add($oiNul, $i64->constInt($nulFormLen, false)), $outIdx);
        $b->branch($writeNext);

        $b->positionAtEnd($writeEscCheck);
        $wNeedsEsc = $b->or(
            $b->icmp(Builder::INT_EQ, $wch, $i8->constInt(\ord('\\'), false)),
            $b->icmp(Builder::INT_EQ, $wch, $i8->constInt(\ord("'"), false))
        );
        $b->branchIf($wNeedsEsc, $writeEsc, $writeLit);

        $b->positionAtEnd($writeEsc);
        $oiEsc = $b->load($outIdx);
        $b->store($i8->constInt(\ord('\\'), false), $b->gep($outChars, $oiEsc));
        $b->store($wch, $b->gep($outChars, $b->add($oiEsc, $i64->constInt(1, false))));
        $b->store($b->add($oiEsc, $i64->constInt(2, false)), $outIdx);
        $b->branch($writeNext);

        $b->positionAtEnd($writeLit);
        $oiLit = $b->load($outIdx);
        $b->store($wch, $b->gep($outChars, $oiLit));
        $b->store($b->add($oiLit, $i64->constInt(1, false)), $outIdx);
        $b->branch($writeNext);

        $b->positionAtEnd($writeNext);
        $b->store($b->add($wi, $i64->constInt(1, false)), $idx);
        $b->branch($writeLoop);

        $b->positionAtEnd($writeDone);
        $oiEnd = $b->load($outIdx);
        $b->store($i8->constInt(\ord("'"), false), $b->gep($outChars, $oiEnd));
        $b->returnValue($result);

        $context->builder->clearInsertionPosition();
        $context->builder = $savedBuilder;
        $context->registerFunction($name, $fn);
    }

    private static function emitThinUnsupportedAbort(Context $context): void
    {
        ValueEchoHelper::echoLiteral(
            $context,
            "var_export(): non-scalar value unsupported in thin standalone AOT without Runtime->vm (#26855)\n"
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20589'
        );
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after VarExportJitHelper compile (#20589)');
        }

        return $fn;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringVarExport bridge (#20589)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
