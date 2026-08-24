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
use PHPCompiler\JIT\HashTableNestedExportLlvm;
use PHPCompiler\JIT\JsonEncodeArrayLlvm;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_json_encode_* via JsonEncodeNestedJitHelper PHP
 * (#9267, #13239, #20816, #27020). Type always-on shells dropped (#32897).
 * Bridges registerFunction before body emit so JsonEncodeArrayLlvm self-lookup
 * works without Type predecls (#32326 InfNan AOT).
 *
 * Embed + thin standalone AOT: {@see JsonEncodeNestedJitHelper} via {@see JitVmHelperLink}
 * (Context-free NestedJIT path — avoids `$ctx->runtime->vm` SIGSEGV on thin AOT).
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class StringJsonEncode
{
    private const HELPER_PATH = '/ext/standard/JsonEncodeNestedJitHelper.php';

    private const ENCODE_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\JsonEncodeNestedJitHelper::encodeValue';

    private const ENCODE_HT_HELPER = 'PHPCompiler\\ext\\standard\\JsonEncodeNestedJitHelper::encodeHashtable';

    private const VALUE_BRIDGE_ENTRY = 'json_encode_value_bridge_entry';

    private const HT_BRIDGE_ENTRY = 'json_encode_array_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_VALUE_HELPER,
        self::ENCODE_HT_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_json_encode_value',
        '__compiler_json_encode_array',
        JsonEncodeQuoteStringRuntime::ABI,
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

        // Thin + embed: publish sg_vm_context before NestedJIT of JsonEncodeJitHelper (#17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);
        NestedVmVariableMethodLlvm::ensureMethod($context, 'resolveindirect');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'array');
        // NestedJIT methods used by slim JsonEncodeJitHelper (#27020 / peer StringHttpBuildQuery).
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tostring');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toint');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tofloat');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'tobool');
        NestedVmVariableMethodLlvm::ensureMethod($context, 'toarray');
        // NestedJIT HashTable::exportKeyValuePairs for encodeHashtable (#12908 / #27020).
        HashTableNestedExportLlvm::ensureLinked($context);
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'findindex');
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'ispackedlist');
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'getnumelements');
        NestedVmHashTableMethodLlvm::ensureMethod($context, 'find');
        // Nested foreach on Variable array values may ref __compiler_is_resource (#27182).
        \PHPCompiler\ext\standard\JitStreamLifecycleKernel::ensureLinkedForUserScriptLowering($context);

        $valueProbe = $context->module->getNamedFunction('__compiler_json_encode_value');
        $htProbe = $context->module->getNamedFunction('__compiler_json_encode_array');
        $quoteProbe = $context->module->getNamedFunction(JsonEncodeQuoteStringRuntime::ABI);
        if (JitVmHelperLink::hasNamedBridgeEntry($valueProbe, self::VALUE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($htProbe, self::HT_BRIDGE_ENTRY)
            && null !== $quoteProbe && $quoteProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }
        if (null !== $valueProbe && $valueProbe->countBasicBlocks() > 0
            && null !== $htProbe && $htProbe->countBasicBlocks() > 0
            && null !== $quoteProbe && $quoteProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        JsonEncodeQuoteStringRuntime::ensureLinked($context);
        self::implementJsonEncodeValueBridge($context);
        self::implementJsonEncodeArrayBridge($context);
        self::registerLinkedRuntime($context);
    }

    /**
     * Associative/packed array encoding via export pairs — bypasses NestedJIT dim-fetch (#26367).
     */
    private static function implementJsonEncodeArrayBridge(Context $context): void
    {
        $abiName = '__compiler_json_encode_array';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::HT_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        JsonEncodeQuoteStringRuntime::ensureLinked($context);
        self::implementJsonEncodeValueBridge($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $htPtr, $i64, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        // Register before body emit: JsonEncodeArrayLlvm → encodeBoxedValue self-calls
        // this ABI. Type empty shells used to pre-register it (#32897 follow-up / #32326).
        $context->registerFunction($abiName, $fn);

        BasicBlockHelper::scopeLoweringToFunction($context, $fn, $abiName, static function () use ($context, $fn): void {
            $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::HT_BRIDGE_ENTRY);
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue(
                JsonEncodeArrayLlvm::encode(
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
     * Scalar float fast path via {@see ZendDoubleStringRuntime} — NestedJIT `(string)` on
     * float SIGSEGVs (#31963). INF/NAN must not become JSON tokens (#32326).
     * Other types still use {@see JsonEncodeNestedJitHelper}.
     */
    private static function implementJsonEncodeValueBridge(Context $context): void
    {
        $abiName = '__compiler_json_encode_value';
        $probe = $context->module->getNamedFunction($abiName);
        if (JitVmHelperLink::hasNamedBridgeEntry($probe, self::VALUE_BRIDGE_ENTRY)) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        ZendDoubleStringRuntime::ensureLinked($context);
        StringJsonDecode::ensureLinked($context);
        self::ensureJitHelperCompiled($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $double = $context->getTypeFromString('double');
        $ft = $context->context->functionType($strPtr, false, $valuePtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        // Register before body emit — recursive/self lookups while lowering (#32897 follow-up).
        $context->registerFunction($abiName, $fn);

        $entry = JitVmHelperLink::bridgeEntryForEmit($fn, self::VALUE_BRIDGE_ENTRY);
        $floatBb = $fn->appendBasicBlock('json_enc_val_float');
        $stringBb = $fn->appendBasicBlock('json_enc_val_string');
        $boolCheckBb = $fn->appendBasicBlock('json_enc_val_bool_check');
        $boolTrueBb = $fn->appendBasicBlock('json_enc_val_bool_true');
        $boolFalseBb = $fn->appendBasicBlock('json_enc_val_bool_false');
        $helperBb = $fn->appendBasicBlock('json_enc_val_helper');

        $context->builder->positionAtEnd($entry);
        $valPtr = $fn->getParam(0);
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        // JIT TYPE_NATIVE_BOOL (=2) collides with VM TYPE_FLOAT — disambiguate before float (#26367).
        $isJitBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(JitVariable::TYPE_NATIVE_BOOL, false)
        );
        $afterJitBoolBb = $fn->appendBasicBlock('json_enc_val_after_jit_bool');
        $context->builder->branchIf($isJitBool, $boolCheckBb, $afterJitBoolBb);

        $context->builder->positionAtEnd($afterJitBoolBb);
        $isVmFloat = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VmVariable::TYPE_FLOAT, false)
        );
        $isJitDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(JitVariable::TYPE_NATIVE_DOUBLE, false)
        );
        $isFloat = $context->builder->or($isVmFloat, $isJitDouble);
        $notFloatBb = $fn->appendBasicBlock('json_enc_val_not_float');
        $context->builder->branchIf($isFloat, $floatBb, $notFloatBb);

        $context->builder->positionAtEnd($notFloatBb);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VmVariable::TYPE_STRING, false)
        );
        $notStringBb = $fn->appendBasicBlock('json_enc_val_not_string');
        $context->builder->branchIf($isString, $stringBb, $notStringBb);

        $context->builder->positionAtEnd($notStringBb);
        $isVmBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VmVariable::TYPE_BOOLEAN, false)
        );
        $context->builder->branchIf($isVmBool, $boolCheckBb, $helperBb);

        $context->builder->positionAtEnd($boolCheckBb);
        // __value__readLong has no TYPE_NATIVE_BOOL arm — returns 0 (#21892 / JitValueBox).
        $boolByte = JitValueBox::readBoolByte($context, $valPtr);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($isTrue, $boolTrueBb, $boolFalseBb);

        $context->builder->positionAtEnd($boolTrueBb);
        $context->builder->returnValue($context->builder->load($context->constantStringFromString('true')));

        $context->builder->positionAtEnd($boolFalseBb);
        $context->builder->returnValue($context->builder->load($context->constantStringFromString('false')));

        $context->builder->positionAtEnd($floatBb);
        try {
            $context->lookupFunction('__value__readDouble');
        } catch (\Throwable) {
            $readDouble = $context->module->addFunction(
                '__value__readDouble',
                $context->context->functionType($double, false, $valuePtr)
            );
            $context->registerFunction('__value__readDouble', $readDouble);
        }
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr);
        // php_json_encode_double: INF/NAN is JSON_ERROR_INF_OR_NAN, not a token (#32326).
        $savedLowering = $context->loweringLlvmFunction;
        $context->loweringLlvmFunction = $fn instanceof LlvmFunction ? $fn : $savedLowering;
        try {
            $context->builder->returnValue(
                ZendDoubleStringRuntime::jsonEncodeNumberOrNull(
                    $context,
                    $fn instanceof LlvmFunction ? $fn : $context->loweringLlvmFunction,
                    $dbl,
                    $fn->getParam(1)
                )
            );
        } finally {
            $context->loweringLlvmFunction = $savedLowering;
        }

        $context->builder->positionAtEnd($stringBb);
        $rawStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $ownedStr = $context->builder->call($context->lookupFunction('__string__separate'), $rawStr);
        $context->builder->returnValue(JsonEncodeQuoteStringRuntime::quote($context, $ownedStr));

        $context->builder->positionAtEnd($helperBb);
        $helperFn = JitVmHelperLink::lookupCompiled($context, self::ENCODE_VALUE_HELPER, '#20816');
        $args = [
            JitNestedHelperCoerce::coerceArgForHelper($context, $valPtr, $helperFn->getParam(0)->typeOf()),
            JitNestedHelperCoerce::coerceArgForHelper($context, $fn->getParam(1), $helperFn->getParam(1)->typeOf()),
        ];
        $result = $context->builder->call($helperFn, ...$args);
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $result, $strPtr)
        );
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20816'
        );
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after JsonEncodeNestedJitHelper compile (#20816/#27020)');
        }

        return $fn;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringJsonEncode bridge (#20816)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
