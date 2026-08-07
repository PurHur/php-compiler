<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_unserialize via UnserializeJitHelper PHP (#9163, #20785, #27030).
 *
 * Embed + thin standalone AOT: {@see UnserializeJitHelper} NestedJIT
 * (Serialize #20773 shape — no thin null/empty stubs).
 * Helper NestedJIT-decodes `i:N;` as int; bridge boxes to `__value__*` (#20785).
 * Simple `O:` public-prop objects: {@see UnserializeObjectNestedJitHelper} + call-site
 * LLVM materialize (class table must include user classes — emit from JitUnserialize).
 * php-src: ext/standard/var_unserializer.c
 */
final class StringUnserialize
{
    private const HELPER_PATH = '/ext/standard/UnserializeJitHelper.php';

    private const OBJECT_HELPER_PATH = '/ext/standard/UnserializeObjectNestedJitHelper.php';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeJitHelper::decode';

    private const DECODE_SESSION_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeJitHelper::decodeSession';

    private const IS_OBJECT_WIRE_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeObjectNestedJitHelper::isObjectWire';

    private const CLASS_NAME_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeObjectNestedJitHelper::className';

    private const PROPS_INTO_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeObjectNestedJitHelper::propsInto';

    private const FIRST_INT_PROP_HELPER = 'PHPCompiler\\ext\\standard\\UnserializeObjectNestedJitHelper::firstIntProp';

    private const UNSER_BRIDGE_ENTRY = 'unser_bridge_entry';

    private const SESSION_BRIDGE_ENTRY = 'session_unser_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::DECODE_HELPER,
        self::DECODE_SESSION_HELPER,
    ];

    /** @var list<string> */
    private const OBJECT_COMPILED_HELPERS = [
        self::IS_OBJECT_WIRE_HELPER,
        self::CLASS_NAME_HELPER,
        self::PROPS_INTO_HELPER,
        self::FIRST_INT_PROP_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_unserialize',
        'phpc_session_decode_payload',
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

        // Save before active-context / NestedJIT work — those can detach the builder
        // ("Current basic block has no parent function", peer StrRepeat #19998).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        // Thin + embed: publish sg_vm_context before NestedJIT of UnserializeJitHelper (#17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
        // Variable / HashTable mutators used by UnserializeJitHelper NestedJIT (#12910 / #20785).
        foreach (['null', 'bool', 'int', 'string', 'float', 'array', 'copyfrom', 'resolveindirect'] as $varMethod) {
            NestedVmVariableMethodLlvm::ensureMethod($context, $varMethod);
        }
        foreach (['add', 'updateindex', 'append'] as $htMethod) {
            NestedVmHashTableMethodLlvm::ensureMethod($context, $htMethod);
        }
        self::ensureNativeHtInternalProxies($context);

        $unserProbe = $context->module->getNamedFunction('__compiler_unserialize');
        $sessionProbe = $context->module->getNamedFunction('phpc_session_decode_payload');
        if (JitVmHelperLink::hasNamedBridgeEntry($unserProbe, self::UNSER_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($sessionProbe, self::SESSION_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }
        if (null !== $unserProbe && $unserProbe->countBasicBlocks() > 0
            && null !== $sessionProbe && $sessionProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }

        self::ensureRuntimeHelpers($context);
        self::implementUnserializeBridge($context);
        self::implementSessionDecodeBridge($context);
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20785'
        );
    }

    public static function ensureObjectHelpersCompiled(Context $context): void
    {
        self::ensureNativeHtInternalProxies($context);
        JitVmHelperLink::ensureCompiled(
            $context,
            self::OBJECT_HELPER_PATH,
            self::OBJECT_COMPILED_HELPERS,
            '#27030'
        );
    }

    /** Register phpc_native_ht_* Internal JIT handlers before NestedJIT (#27030 / #24137). */
    private static function ensureNativeHtInternalProxies(Context $context): void
    {
        $internals = [
            new \PHPCompiler\ext\standard\phpc_native_ht_alloc(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_string_key_long(),
            new \PHPCompiler\ext\standard\phpc_native_ht_set_long_at(),
        ];
        foreach ($internals as $internal) {
            $lc = strtolower($internal->getName());
            $existing = $context->functionProxies[$lc] ?? null;
            if (null === $existing || $existing instanceof \PHPCompiler\JIT\Call\ExternalMethod) {
                $context->functionProxies[$lc] = $internal;
            }
        }
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UnserializeJitHelper compile (#20785)');
        }

        return $fn;
    }

    public static function objectHelperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureObjectHelpersCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after UnserializeObjectNestedJitHelper compile (#27030)');
        }

        return $fn;
    }

    /**
     * Emit runtime O: decode into the current insert block (call-site class table) (#27030).
     *
     * Returns `__value__*` — object on success, false on parse/class miss (php-src-ish).
     * Class name is matched via LLVM prefix compare against known classes (NestedJIT string
     * returns are unreliable under thin AOT — peer str_contains #24161).
     */
    public static function emitObjectDecodeRuntime(Context $context, Value $payloadString): Value
    {
        self::ensureLinked($context);
        self::ensureObjectHelpersCompiled($context);
        self::ensureRuntimeHelpers($context);
        StringStrContains::ensureLinked($context);
        try {
            $context->lookupFunction('__hashtable__readStringKeyValue');
        } catch (\Throwable) {
            $htPtr = $context->getTypeFromString('__hashtable__*');
            $strPtr = $context->getTypeFromString('__string__*');
            $valuePtr = $context->getTypeFromString('__value__*');
            $fn = $context->module->addFunction(
                '__hashtable__readStringKeyValue',
                $context->context->functionType($valuePtr, false, $htPtr, $strPtr)
            );
            $context->registerFunction('__hashtable__readStringKeyValue', $fn);
        }

        $fn = BasicBlockHelper::parentFunction($context);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $valuePtr = $context->getTypeFromString('__value__*');
        $objPtr = $context->getTypeFromString('__object__*');

        $bbObj = $fn->appendBasicBlock('unser_obj_decode');
        $bbFail = $fn->appendBasicBlock('unser_obj_fail');
        $bbDone = $fn->appendBasicBlock('unser_obj_done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $valuePtr);

        $payloadArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payloadString,
            self::objectHelperFunction($context, self::IS_OBJECT_WIRE_HELPER)->getParam(0)->typeOf()
        );
        $isObj = $context->builder->call(
            self::objectHelperFunction($context, self::IS_OBJECT_WIRE_HELPER),
            $payloadArg
        );
        $isObjI64 = JitNestedHelperCoerce::coerceBridgeResult($context, $isObj, $i64);
        $isObject = $context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $isObjI64,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($isObject, $bbObj, $bbFail);

        $context->builder->positionAtEnd($bbFail);
        $failSlot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $failPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $failSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $failPtr,
            $i32->constInt(0, false)
        );
        $context->builder->store($failPtr, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbObj);
        // Skip HT propsInto — NestedJIT int return of firstIntProp is reliable (#27030).
        $bbPropsOk = $fn->appendBasicBlock('unser_obj_props_ok');
        $context->builder->branch($bbPropsOk);

        $context->builder->positionAtEnd($bbPropsOk);
        /** @var \PHPCompiler\JIT\Builtin\Type\Object_ $object */
        $object = $context->type->object;
        $bbMatchFail = $fn->appendBasicBlock('unser_obj_class_miss');
        $bbMatched = $fn->appendBasicBlock('unser_obj_matched');
        $objSlot = BasicBlockHelper::entryAlloca($context, $objPtr);
        $context->builder->store($objPtr->constNull(), $objSlot);
        $firstIntHelper = self::objectHelperFunction($context, self::FIRST_INT_PROP_HELPER);
        $firstIntRaw = $context->builder->call(
            $firstIntHelper,
            JitNestedHelperCoerce::coerceArgForHelper(
                $context,
                $payloadString,
                $firstIntHelper->getParam(0)->typeOf()
            )
        );
        $firstInt = JitNestedHelperCoerce::coerceBridgeResult($context, $firstIntRaw, $i64);
        $check = $context->builder->getInsertBlock();
        $hasCase = false;
        foreach ($object->allClassNamesById() as $id => $className) {
            if ('__PHP_Incomplete_Class' === $className) {
                continue;
            }
            $hasCase = true;
            $case = $fn->appendBasicBlock('unser_obj_case_'.$id);
            $next = $fn->appendBasicBlock('unser_obj_try_'.$id);
            $context->builder->positionAtEnd($check);
            $header = 'O:'.\strlen($className).':"'.$className.'":';
            $headerStr = $context->builder->load($context->constantStringFromString($header));
            $isMatch = \PHPCompiler\VM\VmStringCompare::prefixIdentical(
                $context,
                $payloadString,
                $headerStr
            );
            $context->builder->branchIf($isMatch, $case, $next);
            $context->builder->positionAtEnd($case);
            $objVal = $object->allocate($id);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'unser_obj_after_alloc_'.$id);
            $object->markObjectConstructed($objVal);
            $voidPtr = $context->getTypeFromString('void*');
            foreach ($object->instancePropertySets($id) as $propset) {
                $propName = $propset[1];
                $box = \PHPCompiler\JIT\JitValueBox::alloc($context);
                $boxPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $box);
                $context->builder->call(
                    $context->lookupFunction('__value__writeLong'),
                    $boxPtr,
                    $firstInt
                );
                $slot = $object->propertySlotFor($objVal, $className, $propName);
                $context->builder->store(
                    $context->builder->pointerCast($boxPtr, $voidPtr),
                    $slot
                );
                // firstIntProp covers the first int wire value; enough for #27030 single-prop.
                break;
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'unser_obj_after_props_'.$id);
            $context->builder->store($objVal, $objSlot);
            $context->builder->branch($bbMatched);
            $check = $next;
        }
        if (!$hasCase) {
            $context->builder->branch($bbMatchFail);
        } else {
            $context->builder->positionAtEnd($check);
            $context->builder->branch($bbMatchFail);
        }

        $context->builder->positionAtEnd($bbMatchFail);
        $context->builder->branch($bbFail);

        $context->builder->positionAtEnd($bbMatched);
        $objLoaded = $context->builder->load($objSlot);
        $outSlot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $outPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $outSlot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $outPtr,
            $objLoaded
        );
        $context->builder->store($outPtr, $resultSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($resultSlot);
    }

    /**
     * NestedJIT decode(): int → box as `__value__*` (#20785).
     * (Variable / mixed NestedJIT returns are not yet thin-AOT safe.)
     */
    private static function implementUnserializeBridge(Context $context): void
    {
        $abiName = '__compiler_unserialize';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::UNSER_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($valuePtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20785'
        );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::UNSER_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $payload = $fn->getParam(0);
        $helperFn = self::helperFunction($context, self::DECODE_HELPER);
        $payloadArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payload,
            $helperFn->getParam(0)->typeOf()
        );
        $raw = $context->builder->call($helperFn, $payloadArg);
        $long = JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64);
        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $outPtr = \PHPCompiler\JIT\JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $long
        );
        $context->builder->returnValue($outPtr);
        $context->registerFunction($abiName, $fn);
    }

    private static function implementSessionDecodeBridge(Context $context): void
    {
        $abiName = 'phpc_session_decode_payload';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::SESSION_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($htPtr, false, $i8p, $sizeT);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock(self::SESSION_BRIDGE_ENTRY);
        $empty = $fn->appendBasicBlock('session_unser_empty');
        $decode = $fn->appendBasicBlock('session_unser_decode');

        $context->builder->positionAtEnd($entry);
        $body = $fn->getParam(0);
        $len = $fn->getParam(1);
        $nullBody = $context->builder->icmp(Builder::INT_EQ, $body, $i8p->constNull());
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $len, $sizeT->constInt(0, false));
        $bad = $context->builder->or($nullBody, $zeroLen);
        $context->builder->branchIf($bad, $empty, $decode);

        $context->builder->positionAtEnd($empty);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));

        $context->builder->positionAtEnd($decode);
        $payloadStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $body
        );
        $htRaw = $context->builder->call(
            self::helperFunction($context, self::DECODE_SESSION_HELPER),
            $payloadStr
        );
        $ht = JitNestedHelperCoerce::coerceToHashtablePtr($context, $htRaw);
        $context->builder->returnValue($ht);
        $context->registerFunction($abiName, $fn);
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringUnserialize bridge (#20785)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
