<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\HashTableNestedExportLlvm;
use PHPCompiler\JIT\NestedVmHashTableMethodLlvm;
use PHPCompiler\JIT\NestedVmVariableMethodLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_json_encode_* via JsonEncodeNestedJitHelper PHP
 * (#9267, #13239, #20816, #27020).
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
        if (JitVmHelperLink::hasNamedBridgeEntry($valueProbe, self::VALUE_BRIDGE_ENTRY)
            && JitVmHelperLink::hasNamedBridgeEntry($htProbe, self::HT_BRIDGE_ENTRY)) {
            self::registerLinkedRuntime($context);

            return;
        }
        if (null !== $valueProbe && $valueProbe->countBasicBlocks() > 0
            && null !== $htProbe && $htProbe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_encode_value',
            self::VALUE_BRIDGE_ENTRY,
            [$valuePtr, $i64],
            $strPtr,
            self::ENCODE_VALUE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20816'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_json_encode_array',
            self::HT_BRIDGE_ENTRY,
            [$htPtr, $i64],
            $strPtr,
            self::ENCODE_HT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20816'
        );
        self::registerLinkedRuntime($context);
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
