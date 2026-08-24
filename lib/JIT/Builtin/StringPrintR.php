<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitGettype;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitValueCompare;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\PrintRArrayLlvm;
use PHPCompiler\JIT\PrintRObjectLlvm;
use PHPCompiler\JIT\ValueEchoHelper;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_print_r via PrintRJitHelper PHP (#9190, #13240, #16565, #22668, #23540, #32941).
 *
 * Owns module-local ABI decls (getNamedFunction first) — Type always-on shells removed.
 * Embed: NestedJIT {@see PrintRJitHelper} (php-in-PHP).
 * Thin standalone AOT: scalar + array LLVM bridge (bool/null/int/float/string/array) — NestedJIT
 * of the helper segfaults or throws without Runtime->vm (#23540 / #24220 / #24259).
 * Arrays: {@see PrintRArrayLlvm} (#34497; peer VarExportArrayLlvm / SerializeArrayLlvm).
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringVarExport #20589).
 * Thin standalone AOT publishes sg_vm_context before NestedJIT (#17391 / #23540) on the embed path.
 * php-src: ext/standard/var.c — php_print_r_ex / zend_print_zval_r / PHP_FUNCTION(print_r)
 */
final class StringPrintR
{
    public const HT_ABI = '__compiler_print_r_hashtable';

    public const OBJ_ABI = '__compiler_print_r_object';

    /** value* → extract + {@see OBJ_ABI} (thin/nested; avoids emit-time cross-fn IR) (#34506). */
    public const OBJ_VALUE_ABI = '__compiler_print_r_object_value';

    private const HELPER_PATH = '/ext/standard/PrintRJitHelper.php';

    private const FORMAT_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\PrintRJitHelper::formatValue';

    private const HT_BRIDGE_ENTRY = 'print_r_ht_bridge_entry';

    private const OBJ_BRIDGE_ENTRY = 'print_r_obj_bridge_entry';

    private const OBJ_VALUE_BRIDGE_ENTRY = 'print_r_obj_value_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_VALUE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_print_r',
        self::HT_ABI,
        self::OBJ_ABI,
        self::OBJ_VALUE_ABI,
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

        $probe = $context->module->getNamedFunction('__compiler_print_r');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        // Thin user-script AOT: scalar IR bridge — skip NestedJIT helper (#23540 / #24259).
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

        // Embed / self-host: publish sg_vm_context before NestedJIT of PrintRJitHelper (#17391 / #23540).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        self::ensureJitHelperCompiled($context);
        self::implementBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * Thin standalone AOT: format scalars + arrays like Zend print_r without NestedJIT (#24259 / #34497).
     *
     * php-src zend_print_zval_r: bool true → "1", false/null → ""; int/float/string as themselves.
     */
    private static function implementThinScalarBridge(Context $context): void
    {
        $abiName = '__compiler_print_r';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);
            self::implementPrintRHashtableBridge($context);
            self::implementPrintRObjectBridge($context);
            self::implementPrintRObjectValueBridge($context);

            return;
        }

        StringDir::ensureLinked($context);
        ZendDoubleStringRuntime::ensureLinked($context);
        ObOutputRuntime::ensureLinked($context);
        ValueEchoRuntime::ensureLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);
        self::implementPrintRHashtableBridge($context);
        self::implementPrintRObjectBridge($context);
        self::implementPrintRObjectValueBridge($context);

        $entry = $fn->appendBasicBlock('print_r_thin_scalar_entry');
        $boolBlock = $fn->appendBasicBlock('print_r_thin_bool');
        $longBlock = $fn->appendBasicBlock('print_r_thin_long');
        $doubleBlock = $fn->appendBasicBlock('print_r_thin_double');
        $nullBlock = $fn->appendBasicBlock('print_r_thin_null');
        $stringBlock = $fn->appendBasicBlock('print_r_thin_string');
        $arrayBlock = $fn->appendBasicBlock('print_r_thin_array');
        $objectBlock = $fn->appendBasicBlock('print_r_thin_object');
        $fallback = $fn->appendBasicBlock('print_r_thin_fallback');
        $done = $fn->appendBasicBlock('print_r_thin_done');

        $context->builder->positionAtEnd($entry);
        $arg = $fn->getParam(0);
        // Literal `null` can arrive as a null __value__* (no box) — load would SIGSEGV (#24220).
        $nullPtrBlock = $fn->appendBasicBlock('print_r_thin_null_ptr');
        $havePtr = $fn->appendBasicBlock('print_r_thin_have_ptr');
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $arg,
            $valuePtr->constNull()
        );
        $context->builder->branchIf($isNullPtr, $nullPtrBlock, $havePtr);
        $context->builder->positionAtEnd($nullPtrBlock);
        $nullPtrStr = self::emptyString($context);
        $nullPtrEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);
        $context->builder->positionAtEnd($havePtr);

        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($arg, $map['type'])
        );
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = $fn->appendBasicBlock('print_r_thin_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $arg);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $trueBlock = $fn->appendBasicBlock('print_r_thin_bool_true');
        $falseBlock = $fn->appendBasicBlock('print_r_thin_bool_false');
        $boolDone = $fn->appendBasicBlock('print_r_thin_bool_done');
        $context->builder->branchIf($isTrue, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        // zend_print_zval_r: IS_TRUE → "1"
        $trueStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(1, false),
            $context->builder->pointerCast($context->constantFromString('1'), $i8p)
        );
        $trueEnd = $context->builder->getInsertBlock();
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($falseBlock);
        // zend_print_zval_r: IS_FALSE → ""
        $falseStr = self::emptyString($context);
        $falseEnd = $context->builder->getInsertBlock();
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($boolDone);
        $boolPhi = $context->builder->phi($strPtr);
        $boolPhi->addIncoming($trueStr, $trueEnd);
        $boolPhi->addIncoming($falseStr, $falseEnd);
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = $fn->appendBasicBlock('print_r_thin_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $arg
        );
        // Thin AOT: stream handles are boxed TYPE_NATIVE_LONG — Resource id #N (#34507).
        // formatBoxedNativeLong always uses %lld (#23811); open/closed need RESOURCE_FORMAT.
        $longStr = self::formatThinNativeLongOrResource($context, $fn, $longVal);
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = $fn->appendBasicBlock('print_r_thin_after_double');
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $arg
        );
        $doubleStr = ZendDoubleStringRuntime::formatGcvt($context, $doubleVal);
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterDouble);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_NULL, false)
        );
        $afterNull = $fn->appendBasicBlock('print_r_thin_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        $nullStr = self::emptyString($context);
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterNull);
        // TYPE_STRING carries IS_REFCOUNTED; $kind is already masked with 0x7f above.
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_STRING & 0x7f, false)
        );
        $afterString = $fn->appendBasicBlock('print_r_thin_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $stringStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $arg
        );
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_HASHTABLE & 0x7f, false)
        );
        $afterArray = $fn->appendBasicBlock('print_r_thin_after_array');
        $context->builder->branchIf($isArray, $arrayBlock, $afterArray);

        $context->builder->positionAtEnd($arrayBlock);
        $ht = $context->builder->call($context->lookupFunction('__value__readHashtable'), $arg);
        $arrayStr = $context->builder->call(
            $context->lookupFunction(self::HT_ABI),
            $ht,
            $i64->constInt(0, false)
        );
        $arrayEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterArray);
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_OBJECT & 0x7f, false)
        );
        $context->builder->branchIf($isObject, $objectBlock, $fallback);

        $context->builder->positionAtEnd($objectBlock);
        $objectStr = $context->builder->call(
            $context->lookupFunction(self::OBJ_VALUE_ABI),
            $arg,
            $i64->constInt(0, false)
        );
        $objectEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($fallback);
        self::emitThinUnsupportedAbort($context);
        // Unreachable after abort(); keep a typed return for IR completeness.
        $context->builder->returnValue(self::emptyString($context));

        $context->builder->positionAtEnd($done);
        $result = $context->builder->phi($strPtr);
        $result->addIncoming($nullPtrStr, $nullPtrEnd);
        $result->addIncoming($boolPhi, $boolEnd);
        $result->addIncoming($longStr, $longEnd);
        $result->addIncoming($doubleStr, $doubleEnd);
        $result->addIncoming($nullStr, $nullEnd);
        $result->addIncoming($stringStr, $stringEnd);
        $result->addIncoming($arrayStr, $arrayEnd);
        $result->addIncoming($objectStr, $objectEnd);
        $context->builder->returnValue($result);
    }

    /**
     * Array HT ABI for thin AOT — NestedJIT PrintRJitHelper needs Runtime->vm (#34497).
     */
    private static function implementPrintRHashtableBridge(Context $context): void
    {
        $abiName = self::HT_ABI;
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::HT_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureHelpersForArrayLlvm($context);
        self::ensurePrintRObjectAbiDeclared($context);
        self::ensurePrintRObjectValueAbiDeclared($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $htPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use ($context, $fn): void {
            $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::HT_BRIDGE_ENTRY);
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue(
                PrintRArrayLlvm::encode($context, $fn->getParam(0), $fn->getParam(1))
            );
        });
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    /**
     * Object ABI for thin AOT — NestedJIT PrintRJitHelper needs Runtime->vm (#34506).
     * Peer {@see implementPrintRHashtableBridge} / {@see PrintRObjectLlvm}.
     */
    private static function implementPrintRObjectBridge(Context $context): void
    {
        $abiName = self::OBJ_ABI;
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::OBJ_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::ensureHelpersForArrayLlvm($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $htPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);
        self::ensurePrintRObjectValueAbiDeclared($context);
        self::implementPrintRHashtableBridge($context);

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use ($context, $fn): void {
            $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::OBJ_BRIDGE_ENTRY);
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue(
                PrintRObjectLlvm::encode(
                    $context,
                    $fn->getParam(0),
                    $fn->getParam(1),
                    $fn->getParam(2)
                )
            );
        });
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    /**
     * value*-in object ABI — extract at runtime inside this fn, then OBJ_ABI (#34506).
     */
    private static function implementPrintRObjectValueBridge(Context $context): void
    {
        $abiName = self::OBJ_VALUE_ABI;
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::OBJ_VALUE_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        self::implementPrintRObjectBridge($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $valuePtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use ($context, $fn): void {
            $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::OBJ_VALUE_BRIDGE_ENTRY);
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue(
                PrintRObjectLlvm::encodeFromValueBox(
                    $context,
                    $fn->getParam(0),
                    $fn->getParam(1)
                )
            );
        });
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    /** Declare OBJ_VALUE_ABI without body (HT nested-object arms may lookup) (#34506). */
    private static function ensurePrintRObjectValueAbiDeclared(Context $context): void
    {
        $abiName = self::OBJ_VALUE_ABI;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $valuePtr, $i64);
        $fn = $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);
    }

    /** Declare/register OBJ_ABI without emitting body (avoids HT↔OBJ init cycles) (#34506). */
    private static function ensurePrintRObjectAbiDeclared(Context $context): void
    {
        $abiName = self::OBJ_ABI;
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $htPtr, $i64);
        $fn = $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);
    }

    /** Ensure libc/string helpers before {@see PrintRArrayLlvm} emit (#34497). */
    public static function ensureHelpersForArrayLlvm(Context $context): void
    {
        StringDir::ensureLinked($context);
        ZendDoubleStringRuntime::ensureLinked($context);
    }

    /**
     * Thin AOT long → string: Resource id #N for open/closed streams, else digits (#34507).
     */
    private static function formatThinNativeLongOrResource(
        Context $context,
        LlvmFunction $fn,
        Value $longVal
    ): Value {
        $longBlock = $context->builder->getInsertBlock();
        StringDir::ensureLinked($context);
        StreamLifecycleRuntime::ensureLinked($context);
        $context->builder->positionAtEnd($longBlock);

        $strPtr = $context->getTypeFromString('__string__*');
        $isOpen = JitValueCompare::nativeLongIsResource($context, $longVal);
        $wasClosed = JitGettype::isClosedStreamHandle($context, $longVal);
        $isClosed = $context->builder->and($wasClosed, $context->builder->not($isOpen));
        $isRes = $context->builder->or($isOpen, $isClosed);

        // Append on $fn explicitly — ensureLinked must not leave insert on another fn (#34507).
        $resBlock = $fn->appendBasicBlock('print_r_thin_long_resource');
        $plainBlock = $fn->appendBasicBlock('print_r_thin_long_plain');
        $doneBlock = $fn->appendBasicBlock('print_r_thin_long_done');
        $context->builder->branchIf($isRes, $resBlock, $plainBlock);

        $context->builder->positionAtEnd($plainBlock);
        $plainStr = VmResourceIdString::formatBoxedNativeLong($context, $longVal);
        $context->builder->positionAtEnd($plainBlock);
        $plainEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($resBlock);
        $resStr = VmResourceIdString::formatResourceIdLabel($context, $longVal);
        $context->builder->positionAtEnd($resBlock);
        $resEnd = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($plainStr, $plainEnd);
        $phi->addIncoming($resStr, $resEnd);

        return $phi;
    }

    private static function emptyString(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
    }

    /** Loud abort for non-scalar thin AOT — peer StringVarDump (#23540). */
    private static function emitThinUnsupportedAbort(Context $context): void
    {
        ValueEchoHelper::echoLiteral(
            $context,
            "print_r(): non-scalar value unsupported in thin standalone AOT without Runtime->vm (#23540)\n"
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_print_r';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($strPtr, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('print_r_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $result = $context->builder->call(
            self::helperFunction($context, self::FORMAT_VALUE_HELPER),
            $fn->getParam(0)
        );
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22668');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23540'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringPrintR bridge (#9190)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
