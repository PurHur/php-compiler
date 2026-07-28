<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitValueBox;
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
 * JIT/AOT link for __compiler_json_decode via JsonDecodeJitHelper PHP (#9359, #13228, #20829).
 *
 * Embed + thin standalone AOT: assoc containers via {@see JsonDecodeJitHelper::decodeAssocArray}
 * + {@see phpc_json_decode_assoc_ht}; scalars keep int-wire (#20829 / #24137).
 * php-src: ext/json/php_json.c — php_json_decode_ex / php_json_validate
 */
final class StringJsonDecode
{
    private const DECODE_HELPER_PATH = '/ext/standard/JsonDecodeJitHelper.php';

    private const VALIDATE_HELPER_PATH = '/ext/standard/JsonValidateJitHelper.php';

    private const DECODE_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::decode';

    private const IS_CONTAINER_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::isAssocContainer';

    private const DECODE_ARRAY_HELPER = 'PHPCompiler\\ext\\standard\\JsonDecodeJitHelper::decodeAssocArray';

    private const ASSOC_HT_ABI = 'phpc_json_decode_assoc_ht';

    private const ASSOC_HT_BRIDGE_ENTRY = 'json_decode_assoc_ht_entry';

    private const VALIDATE_HELPER = 'PHPCompiler\\ext\\standard\\JsonValidateJitHelper::validate';

    private const LAST_ERROR_HELPER = 'PHPCompiler\\ext\\standard\\JsonValidateJitHelper::lastError';

    private const LAST_ERROR_MSG_HELPER = 'PHPCompiler\\ext\\standard\\JsonValidateJitHelper::lastErrorMsg';

    private const DECODE_BRIDGE_ENTRY = 'json_decode_bridge_entry';

    private const VALIDATE_BRIDGE_ENTRY = 'json_validate_bridge_entry';

    private const LAST_ERROR_BRIDGE_ENTRY = 'json_last_error_bridge_entry';

    private const LAST_ERROR_MSG_BRIDGE_ENTRY = 'json_last_error_msg_bridge_entry';

    /** @var list<string> */
    private const DECODE_COMPILED_HELPERS = [
        self::DECODE_HELPER,
        self::IS_CONTAINER_HELPER,
        self::DECODE_ARRAY_HELPER,
    ];

    /** @var list<string> */
    private const VALIDATE_COMPILED_HELPERS = [
        self::VALIDATE_HELPER,
        self::LAST_ERROR_HELPER,
        self::LAST_ERROR_MSG_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_json_decode',
        '__compiler_json_validate',
        '__compiler_json_last_error',
        '__compiler_json_last_error_msg',
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    /** Standalone AOT: JSON POST helper for superglobals_refresh.c (#7389). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);

        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
        foreach (['null', 'bool', 'int', 'string', 'float', 'array', 'copyfrom', 'resolveindirect'] as $varMethod) {
            NestedVmVariableMethodLlvm::ensureMethod($context, $varMethod);
        }
        foreach (['add', 'addindex', 'updateindex', 'append'] as $htMethod) {
            NestedVmHashTableMethodLlvm::ensureMethod($context, $htMethod);
        }
        self::ensureHashtableValueHelpers($context);

        $decodeProbe = $context->module->getNamedFunction('__compiler_json_decode');
        $validateProbe = $context->module->getNamedFunction('__compiler_json_validate');
        $lastErrProbe = $context->module->getNamedFunction('__compiler_json_last_error');
        $lastMsgProbe = $context->module->getNamedFunction('__compiler_json_last_error_msg');
        if (JitVmHelperLink::hasNamedBridgeEntry($decodeProbe, self::DECODE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($validateProbe, self::VALIDATE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($lastErrProbe, self::LAST_ERROR_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($lastMsgProbe, self::LAST_ERROR_MSG_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }
        if (null !== $decodeProbe && $decodeProbe->countBasicBlocks() > 0
            && null !== $validateProbe && $validateProbe->countBasicBlocks() > 0
            && null !== $lastErrProbe && $lastErrProbe->countBasicBlocks() > 0
            && null !== $lastMsgProbe && $lastMsgProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

            return;
        }

        self::implementAssocHtBridge($context);
        self::implementDecodeBridge($context);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_validate',
            self::VALIDATE_BRIDGE_ENTRY,
            [$strPtr, $i64],
            $i64,
            self::VALIDATE_HELPER,
            self::VALIDATE_HELPER_PATH,
            self::VALIDATE_COMPILED_HELPERS,
            '#20829'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_last_error',
            self::LAST_ERROR_BRIDGE_ENTRY,
            [],
            $i64,
            self::LAST_ERROR_HELPER,
            self::VALIDATE_HELPER_PATH,
            self::VALIDATE_COMPILED_HELPERS,
            '#20829'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_last_error_msg',
            self::LAST_ERROR_MSG_BRIDGE_ENTRY,
            [],
            $strPtr,
            self::LAST_ERROR_MSG_HELPER,
            self::VALIDATE_HELPER_PATH,
            self::VALIDATE_COMPILED_HELPERS,
            '#20829'
        );
        self::registerLinkedRuntime($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::DECODE_HELPER_PATH,
            self::DECODE_COMPILED_HELPERS,
            '#20829'
        );
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after JsonDecodeJitHelper compile (#20829)');
        }

        return $fn;
    }

    private static function implementAssocHtBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ASSOC_HT_ABI,
            self::ASSOC_HT_BRIDGE_ENTRY,
            [$strPtr],
            $htPtr,
            self::DECODE_ARRAY_HELPER,
            self::DECODE_HELPER_PATH,
            [self::DECODE_ARRAY_HELPER],
            '#24137'
        );
    }

    /**
     * NestedJIT decode(): container HT or int scalar → box as `__value__*` (#20829 / #24137).
     */
    private static function implementDecodeBridge(Context $context): void
    {
        $abiName = '__compiler_json_decode';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::DECODE_BRIDGE_ENTRY)) {
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
            self::DECODE_HELPER_PATH,
            self::DECODE_COMPILED_HELPERS,
            '#20829'
        );

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::DECODE_BRIDGE_ENTRY);
        $context->builder->positionAtEnd($entry);
        $payload = $fn->getParam(0);
        $payloadArg = JitNestedHelperCoerce::coerceArgForHelper(
            $context,
            $payload,
            $strPtr
        );
        // Own encode temps/views before NestedJIT decode (#24137 / peer #5965).
        $payloadArg = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $payloadArg
        );

        $containerBb = $fn->appendBasicBlock('json_decode_container');
        $scalarBb = $fn->appendBasicBlock('json_decode_scalar');
        $isContainerRaw = $context->builder->call(
            self::helperFunction($context, self::IS_CONTAINER_HELPER),
            $payloadArg
        );
        $isContainer = JitNestedHelperCoerce::coerceHelperScalarResult($context, $isContainerRaw, $i64);
        $isContainerTrue = $context->builder->icmp(
            Builder::INT_NE,
            $isContainer,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($isContainerTrue, $containerBb, $scalarBb);

        $context->builder->positionAtEnd($containerBb);
        $ht = $context->builder->call(
            $context->lookupFunction(self::ASSOC_HT_ABI),
            $payloadArg
        );
        $context->builder->returnValue(self::boxHashtableValue($context, $ht));

        $context->builder->positionAtEnd($scalarBb);
        $helperFn = self::helperFunction($context, self::DECODE_HELPER);
        $raw = $context->builder->call($helperFn, $payloadArg);
        $long = JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $i64);
        $slot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $long
        );
        $context->builder->returnValue($outPtr);
        $context->registerFunction($abiName, $fn);
    }

    private static function boxHashtableValue(Context $context, Value $ht): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->refcount->addref($ht);

        return $ptr;
    }

    private static function ensureHashtableValueHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
            ['__string__separate', $strPtr, [$strPtr]],
        ] as [$name, $ret, $params]) {
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
                throw new \LogicException($name.' missing after StringJsonDecode bridge (#20829)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
