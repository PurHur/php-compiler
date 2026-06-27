<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for password_hash/verify/crypt/get_info via PasswordJitHelper PHP (#9908).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\PasswordJitHelper}; thin LLVM
 * bridges forward the ABI (#9908, #12869).
 * SSOT: {@see \PHPCompiler\ext\standard\VmPassword}
 * php-src: ext/standard/password.c
 */
final class PasswordCryptoRuntime
{
    private const HELPER_PATH = '/ext/standard/PasswordJitHelper.php';

    private const HASH_HELPER = 'PHPCompiler\\ext\\standard\\PasswordJitHelper::hashArgv';

    private const VERIFY_HELPER = 'PHPCompiler\\ext\\standard\\PasswordJitHelper::verifyArgv';

    private const CRYPT_HELPER = 'PHPCompiler\\ext\\standard\\PasswordJitHelper::cryptArgv';

    private const GET_INFO_HELPER = 'PHPCompiler\\ext\\standard\\PasswordJitHelper::getInfoHashtable';

    private const NEEDS_REHASH_HELPER = 'PHPCompiler\\ext\\standard\\PasswordJitHelper::needsRehashArgv';

    private const ALGOS_HELPER = 'PHPCompiler\\ext\\standard\\PasswordJitHelper::algosHashtable';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::HASH_HELPER,
        self::VERIFY_HELPER,
        self::CRYPT_HELPER,
        self::GET_INFO_HELPER,
        self::NEEDS_REHASH_HELPER,
        self::ALGOS_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_password_hash',
        '__compiler_password_verify',
        '__compiler_crypt',
        '__compiler_password_get_info',
        '__compiler_password_needs_rehash',
        '__compiler_password_algos',
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
        $probe = $context->module->getNamedFunction('__compiler_password_hash');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, '__compiler_password_hash', self::implementHashBridge(...));
        self::implementIfMissing($context, '__compiler_password_verify', self::implementVerifyBridge(...));
        self::implementIfMissing($context, '__compiler_crypt', self::implementCryptBridge(...));
        self::implementIfMissing($context, '__compiler_password_get_info', self::implementGetInfoBridge(...));
        self::implementIfMissing($context, '__compiler_password_needs_rehash', self::implementNeedsRehashBridge(...));
        self::implementIfMissing($context, '__compiler_password_algos', self::implementAlgosBridge(...));
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = $context->lookupFunction($name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementHashBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_hash_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::HASH_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function implementVerifyBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_verify_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::VERIFY_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $context->getTypeFromString('int32'))
        );
    }

    private static function implementCryptBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_crypt_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::CRYPT_HELPER),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function implementGetInfoBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_info_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::GET_INFO_HELPER),
            [$fn->getParam(0)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw)
        );
    }

    private static function implementNeedsRehashBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_nrh_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::NEEDS_REHASH_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $context->getTypeFromString('int32'))
        );
    }

    private static function implementAlgosBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pw_algos_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::ALGOS_HELPER),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceToHashtablePtr($context, $raw)
        );
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after PasswordJitHelper compile (#9908)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'PasswordJitHelper.php');
            if (null === $block) {
                throw new \LogicException('PasswordJitHelper.php parseAndCompile failed (#9908)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT password (#9908)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after PasswordCryptoRuntime bridge (#9908)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
