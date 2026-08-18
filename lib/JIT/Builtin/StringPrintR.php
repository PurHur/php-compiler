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
 * JIT/AOT link for __compiler_print_r via PrintRJitHelper PHP (#9190, #13240, #16565, #22668, #23540).
 *
 * Embed: NestedJIT {@see PrintRJitHelper} (php-in-PHP).
 * Thin standalone AOT: scalar LLVM bridge (bool/null/int/float/string) — NestedJIT of the helper
 * segfaults or throws without Runtime->vm (#23540 / #24220 / #24259). Non-scalar thin AOT aborts
 * with a stderr diagnostic (peer StringVarDump).
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringVarExport #20589).
 * Thin standalone AOT publishes sg_vm_context before NestedJIT (#17391 / #23540) on the embed path.
 * php-src: ext/standard/var.c — php_print_r_ex / zend_print_zval_r
 */
final class StringPrintR
{
    private const HELPER_PATH = '/ext/standard/PrintRJitHelper.php';

    private const FORMAT_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\PrintRJitHelper::formatValue';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_VALUE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_print_r',
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
     * Thin standalone AOT: format scalars like Zend print_r without NestedJIT (#24259 / #24220).
     *
     * php-src zend_print_zval_r: bool true → "1", false/null → ""; int/float/string as themselves.
     */
    private static function implementThinScalarBridge(Context $context): void
    {
        $abiName = '__compiler_print_r';
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
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('print_r_thin_scalar_entry');
        $boolBlock = $fn->appendBasicBlock('print_r_thin_bool');
        $longBlock = $fn->appendBasicBlock('print_r_thin_long');
        $doubleBlock = $fn->appendBasicBlock('print_r_thin_double');
        $nullBlock = $fn->appendBasicBlock('print_r_thin_null');
        $stringBlock = $fn->appendBasicBlock('print_r_thin_string');
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
        $longStr = VmResourceIdString::formatBoxedNativeLong($context, $longVal);
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
        $context->builder->branchIf($isString, $stringBlock, $fallback);

        $context->builder->positionAtEnd($stringBlock);
        $stringStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $arg
        );
        $stringEnd = $context->builder->getInsertBlock();
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
        $context->builder->returnValue($result);
        $context->registerFunction($abiName, $fn);
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
