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
use PHPCompiler\VM\EnumCasePropertyJitHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_var_dump via VarDumpJitHelper PHP (#9195, #13241, #16565, #23143, #23540, #32941, #34207).
 *
 * Owns module-local ABI decls (getNamedFunction first) — Type always-on shells removed.
 * Embed: NestedJIT {@see VarDumpJitHelper} (php-in-PHP).
 * Thin standalone AOT: scalar + enum-case LLVM bridge — NestedJIT of the helper
 * segfaults on `$ctx->runtime->vm` class-id layout (#23540 / #16075). Other non-scalars
 * thin AOT abort with a stderr diagnostic (not silent SIGABRT).
 * Thin body is deferred to {@see ensureLinkedAtCallSite()} so DECLARE_ENUM has run (#34207).
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StringVarExport #20589).
 * php-src: ext/standard/var.c — php_var_dump_ex / PHP_FUNCTION(var_dump)
 */
final class StringVarDump
{
    private const HELPER_PATH = '/ext/standard/VarDumpJitHelper.php';

    private const FORMAT_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\VarDumpJitHelper::formatVariableValue';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::FORMAT_VALUE_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_var_dump',
    ];

    public static function ensureLinked(Context $context): void
    {
        // Type::initialize / early ensureLinked: declare only under thin AOT so enum
        // class ids from DECLARE_ENUM are visible when the body is emitted (#34207).
        self::implement($context, false);
    }

    /**
     * Emit thin scalar/enum body at the var_dump() call site (after enum declare pass).
     */
    public static function ensureLinkedAtCallSite(Context $context): void
    {
        self::implement($context, true);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context, true);
    }

    public static function implement(Context $context, bool $emitThinBody = false): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_var_dump');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        // Thin user-script AOT: scalar+enum IR bridge — skip NestedJIT helper (#23540 / #34207).
        if ($context->isThinStandaloneAotMain()) {
            if (!$emitThinBody) {
                self::ensureThinDeclaration($context);
            } else {
                self::implementThinScalarBridge($context);
            }
            self::registerLinkedRuntime($context);
            if (null !== $savedInsert) {
                BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
            } else {
                $context->builder->clearInsertionPosition();
            }

            return;
        }

        // Embed / self-host: publish sg_vm_context before NestedJIT of VarDumpJitHelper (#17391).
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

    /** Declare `__compiler_var_dump` without a body — filled at the call site (#34207). */
    private static function ensureThinDeclaration(Context $context): void
    {
        $abiName = '__compiler_var_dump';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = $context->module->addFunction($abiName, $ft);
        $context->registerFunction($abiName, $fn);
    }

    /**
     * Thin standalone AOT: dump scalars + enum cases like Zend without NestedJIT (#23540 / #34207).
     *
     * Uses {@see ValueEchoHelper} / ob echo ABI already linked for `echo` in the same binary.
     * Enum arm: php-src `php_var_dump` prints `enum(Class::Case)` for enum case objects.
     */
    private static function implementThinScalarBridge(Context $context): void
    {
        $abiName = '__compiler_var_dump';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        ObOutputRuntime::ensureLinked($context);
        ValueEchoRuntime::ensureLinked($context);
        ZendDoubleStringRuntime::ensureLinked($context);

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('var_dump_thin_scalar_entry');
        $boolBlock = $fn->appendBasicBlock('var_dump_thin_bool');
        $longBlock = $fn->appendBasicBlock('var_dump_thin_long');
        $doubleBlock = $fn->appendBasicBlock('var_dump_thin_double');
        $nullBlock = $fn->appendBasicBlock('var_dump_thin_null');
        $stringBlock = $fn->appendBasicBlock('var_dump_thin_string');
        $objectBlock = $fn->appendBasicBlock('var_dump_thin_object');
        $fallback = $fn->appendBasicBlock('var_dump_thin_fallback');
        $done = $fn->appendBasicBlock('var_dump_thin_done');

        $context->builder->positionAtEnd($entry);
        $arg = $fn->getParam(0);
        // Literal `null` can arrive as a null __value__* (no box) — load would SIGSEGV (#24220).
        $nullPtrBlock = $fn->appendBasicBlock('var_dump_thin_null_ptr');
        $havePtr = $fn->appendBasicBlock('var_dump_thin_have_ptr');
        $isNullPtr = $context->builder->icmp(
            Builder::INT_EQ,
            $arg,
            $valuePtr->constNull()
        );
        $context->builder->branchIf($isNullPtr, $nullPtrBlock, $havePtr);
        $context->builder->positionAtEnd($nullPtrBlock);
        ValueEchoHelper::echoLiteral($context, "NULL\n");
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
        $afterBool = $fn->appendBasicBlock('var_dump_thin_after_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $arg);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $trueBlock = $fn->appendBasicBlock('var_dump_thin_bool_true');
        $falseBlock = $fn->appendBasicBlock('var_dump_thin_bool_false');
        $boolDone = $fn->appendBasicBlock('var_dump_thin_bool_done');
        $context->builder->branchIf($isTrue, $trueBlock, $falseBlock);
        $context->builder->positionAtEnd($trueBlock);
        ValueEchoHelper::echoLiteral($context, "bool(true)\n");
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($falseBlock);
        ValueEchoHelper::echoLiteral($context, "bool(false)\n");
        $context->builder->branch($boolDone);
        $context->builder->positionAtEnd($boolDone);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = $fn->appendBasicBlock('var_dump_thin_after_long');
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $arg
        );
        ValueEchoHelper::echoLiteral($context, 'int(');
        // Plain %lld path — resource handles are not var_dump ints (#23540).
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_ll'),
            $longVal
        );
        ValueEchoHelper::echoLiteral($context, ")\n");
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = $fn->appendBasicBlock('var_dump_thin_after_double');
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call(
            $context->lookupFunction('__value__readDouble'),
            $arg
        );
        // php-src var.c php_var_dump IS_DOUBLE: %.*H PG(serialize_precision) (#32328)
        // with zend_gcvt INF/NAN tokens, not libc snprintf "inf"/"nan" (#32321 / #32316).
        ValueEchoHelper::echoLiteral($context, 'float(');
        $formatted = ZendDoubleStringRuntime::formatVarDumpH($context, $doubleVal);
        ValueEchoHelper::echoStringVariable(
            $context,
            new JitVariable(
                $context,
                JitVariable::TYPE_STRING,
                JitVariable::KIND_VALUE,
                $formatted
            )
        );
        ValueEchoHelper::echoLiteral($context, ")\n");
        $context->builder->branch($done);

        // null and string are scalars too — without these arms they reached the non-scalar abort,
        // so var_dump(null) and var_dump('hi') died in thin AOT (#24220).
        $context->builder->positionAtEnd($afterDouble);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_NULL, false)
        );
        $afterNull = $fn->appendBasicBlock('var_dump_thin_after_null');
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        ValueEchoHelper::echoLiteral($context, "NULL\n");
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterNull);
        // TYPE_STRING carries IS_REFCOUNTED; $kind is already masked with 0x7f above.
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_STRING & 0x7f, false)
        );
        $afterString = $fn->appendBasicBlock('var_dump_thin_after_string');
        $context->builder->branchIf($isString, $stringBlock, $afterString);

        $context->builder->positionAtEnd($stringBlock);
        $strPtr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $arg
        );
        $lenOffset = $context->structFieldIndex($strPtr, 'length');
        $strLen = $context->builder->load(
            $context->builder->structGep($strPtr, $lenOffset)
        );
        ValueEchoHelper::echoLiteral($context, 'string(');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_ll'),
            $strLen
        );
        ValueEchoHelper::echoLiteral($context, ') "');
        // Byte-exact echo: var_dump() must not stop at an embedded NUL, so length-bounded like
        // ValueEchoHelper::echoStringVariable() rather than a C-string echo.
        $valOffset = $context->structFieldIndex($strPtr, 'value');
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_echo_substr'),
            $context->builder->structGep($strPtr, $valOffset),
            $context->builder->zExt($strLen, $context->getTypeFromString('size_t'))
        );
        ValueEchoHelper::echoLiteral($context, "\"\n");
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterString);
        // TYPE_OBJECT carries IS_REFCOUNTED; enum cases are immortal singletons (#4445 / #34207).
        $isObject = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(JitVariable::TYPE_OBJECT & 0x7f, false)
        );
        $context->builder->branchIf($isObject, $objectBlock, $fallback);

        $context->builder->positionAtEnd($objectBlock);
        self::emitThinEnumObjectDump($context, $fn, $arg, $done, $fallback);

        $context->builder->positionAtEnd($fallback);
        self::emitThinUnsupportedAbort($context);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    /**
     * php-src php_var_dump: enum case object → `enum(%s::%s)\n` (ext/standard/var.c).
     *
     * Matches class_id against {@see Type\Object_::knownEnumClassIdsToNames()}; reads
     * SLOT_NAME like {@see Type\ObjectEnumCasePropertyLlvm}. Non-enum objects fall through.
     */
    private static function emitThinEnumObjectDump(
        Context $context,
        LlvmFunction $fn,
        \PHPLLVM\Value $arg,
        \PHPLLVM\BasicBlock $done,
        \PHPLLVM\BasicBlock $fallback
    ): void {
        $objectType = $context->type->object;
        $enumEntries = $objectType->knownEnumClassIdsToNames();
        if ([] === $enumEntries) {
            $context->builder->branch($fallback);

            return;
        }

        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $arg
        );
        $objMap = $context->structFieldMap['__object__'];
        $classId = $context->builder->load(
            $context->builder->structGep($objPtr, $objMap['class_id'])
        );
        $i64 = $context->getTypeFromString('int64');
        $strTy = $context->getTypeFromString('__string__*');
        $ids = array_keys($enumEntries);
        $lastIdx = count($ids) - 1;
        foreach ($ids as $idx => $id) {
            $matchBlock = $fn->appendBasicBlock('var_dump_thin_enum_'.$id);
            $nextBlock = $idx === $lastIdx
                ? $fallback
                : $fn->appendBasicBlock('var_dump_thin_enum_next_'.$id);
            $context->builder->branchIf(
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $classId,
                    $i64->constInt($id, false)
                ),
                $matchBlock,
                $nextBlock
            );
            $context->builder->positionAtEnd($matchBlock);
            $className = $enumEntries[$id];
            ValueEchoHelper::echoLiteral($context, 'enum('.$className.'::');
            $nameSlot = $objectType->propertySlotPtr($objPtr, EnumCasePropertyJitHelper::SLOT_NAME);
            $nameLoaded = $context->builder->load($nameSlot);
            $nameStr = $context->builder->pointerCast($nameLoaded, $strTy);
            $lenOffset = $context->structFieldIndex($nameStr, 'length');
            $strLen = $context->builder->load(
                $context->builder->structGep($nameStr, $lenOffset)
            );
            $valOffset = $context->structFieldIndex($nameStr, 'value');
            $context->builder->call(
                $context->lookupFunction('__phpc_ob_echo_substr'),
                $context->builder->structGep($nameStr, $valOffset),
                $context->builder->zExt($strLen, $context->getTypeFromString('size_t'))
            );
            ValueEchoHelper::echoLiteral($context, ")\n");
            $context->builder->branch($done);
            $context->builder->positionAtEnd($nextBlock);
        }
    }

    /** Loud abort for non-scalar thin AOT — replaces silent SIGABRT (#23540). */
    private static function emitThinUnsupportedAbort(Context $context): void
    {
        ValueEchoHelper::echoLiteral(
            $context,
            "var_dump(): non-scalar value unsupported in thin standalone AOT without Runtime->vm (#23540)\n"
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function implementBridge(Context $context): void
    {
        $abiName = '__compiler_var_dump';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $voidTy = $context->getTypeFromString('void');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($voidTy, false, $valuePtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('var_dump_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            self::helperFunction($context, self::FORMAT_VALUE_HELPER),
            $fn->getParam(0),
            $i64->constInt(0, false)
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23143');
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
                throw new \LogicException($name.' missing after StringVarDump bridge (#9195)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
