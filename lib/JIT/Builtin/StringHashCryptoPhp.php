<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;

/**
 * JIT/AOT link for hash crypto via HashCryptoJitHelper PHP (#9164, #21026).
 *
 * Embed + thin standalone AOT: {@see HashCryptoJitHelper} via {@see JitVmHelperLink}
 * (HashEquals #20469 / HashAlgos #20652 shape — no thin-standalone libcrypto ABI fork).
 * NestedJIT leaf: {@see \phpc_hash_crypto_hash} → {@see \PHPCompiler\ext\hash\JitHashCryptoKernel} EVP.
 */
final class StringHashCryptoPhp
{
    private const HELPER_PATH = '/ext/standard/HashCryptoJitHelper.php';

    private const HASH_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hash';

    private const HMAC_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashHmac';

    private const PBKDF2_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashPbkdf2';

    private const HKDF_HELPER = 'PHPCompiler\\ext\\standard\\HashCryptoJitHelper::hashHkdf';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HASH_HELPER,
        self::HMAC_HELPER,
        self::PBKDF2_HELPER,
        self::HKDF_HELPER,
    ];

    private const HASH_BRIDGE = 'hc_hash_bridge_entry';

    private const HMAC_BRIDGE = 'hc_hmac_bridge_entry';

    private const PBKDF2_BRIDGE = 'hc_pbkdf2_bridge_entry';

    private const HKDF_BRIDGE = 'hc_hkdf_bridge_entry';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_hash',
        '__compiler_hash_hmac',
        '__compiler_hash_pbkdf2',
        '__compiler_hash_hkdf',
    ];

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction('__compiler_hash');
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && JitVmHelperLink::hasNamedBridgeEntry($probe, self::HASH_BRIDGE)) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        \PHPCompiler\ext\hash\JitHashCryptoKernel::ensureEvpLeaves($context);
        self::implementHashBridge($context);
        self::implementHmacBridge($context);
        self::implementPbkdf2Bridge($context);
        self::implementHkdfBridge($context);
        self::registerLinkedRuntime($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function implementHashBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_hash',
            self::HASH_BRIDGE,
            [$strPtr, $strPtr, $i32],
            $strPtr,
            self::HASH_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21026'
        );
    }

    private static function implementHmacBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_hash_hmac',
            self::HMAC_BRIDGE,
            [$strPtr, $strPtr, $strPtr, $i32],
            $strPtr,
            self::HMAC_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21026'
        );
    }

    private static function implementPbkdf2Bridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_hash_pbkdf2',
            self::PBKDF2_BRIDGE,
            [$strPtr, $strPtr, $strPtr, $i64, $i64, $i32],
            $strPtr,
            self::PBKDF2_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21026'
        );
    }

    private static function implementHkdfBridge(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        JitVmHelperLink::ensureBridge(
            $context,
            '__compiler_hash_hkdf',
            self::HKDF_BRIDGE,
            [$strPtr, $strPtr, $i64, $strPtr, $strPtr],
            $strPtr,
            self::HKDF_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21026'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after StringHashCryptoPhp bridge (#21026)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
