<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\NestedVmActiveContextLlvm;
use PHPCompiler\JIT\VmActiveContextInitLlvm;
use PHPCompiler\JIT\VmActiveContextLlvm;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_serialize_* via SerializeJitHelper PHP (#9180, #20773).
 *
 * Embed + thin standalone AOT: {@see SerializeJitHelper} via {@see JitVmHelperLink}
 * (VarExport #20589 / Htmlspecialchars #20487 shape — no thin null stubs).
 * php-src: ext/standard/var.c — php_var_serialize
 */
final class StringSerialize
{
    private const HELPER_PATH = '/ext/standard/SerializeJitHelper.php';

    private const ENCODE_VALUE_HELPER = 'PHPCompiler\\ext\\standard\\SerializeJitHelper::encodeValue';

    private const ENCODE_HT_HELPER = 'PHPCompiler\\ext\\standard\\SerializeJitHelper::encodeHashtable';

    private const VALUE_BRIDGE_ENTRY = 'serialize_value_bridge_entry';

    private const HT_BRIDGE_ENTRY = 'serialize_ht_bridge_entry';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::ENCODE_VALUE_HELPER,
        self::ENCODE_HT_HELPER,
    ];

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_serialize_value',
        '__compiler_serialize_hashtable',
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

        // Thin + embed: publish sg_vm_context before NestedJIT of SerializeJitHelper (#17391).
        VmActiveContextInitLlvm::requestThinStandaloneInit($context);
        VmActiveContextLlvm::ensureAbi($context);
        NestedVmActiveContextLlvm::ensureMethod($context);
        DomInstanceMethodRuntime::ensureActiveContextProxy($context);

        $valueProbe = $context->module->getNamedFunction('__compiler_serialize_value');
        $htProbe = $context->module->getNamedFunction('__compiler_serialize_hashtable');
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
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_serialize_value',
            self::VALUE_BRIDGE_ENTRY,
            [$valuePtr],
            $strPtr,
            self::ENCODE_VALUE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20773'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_serialize_hashtable',
            self::HT_BRIDGE_ENTRY,
            [$htPtr],
            $strPtr,
            self::ENCODE_HT_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20773'
        );
        self::registerLinkedRuntime($context);
    }

    public static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#20773'
        );
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SerializeJitHelper compile (#20773)');
        }

        return $fn;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringSerialize bridge (#20773)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
