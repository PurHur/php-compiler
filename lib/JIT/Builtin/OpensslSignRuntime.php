<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\ext\openssl\VmOpensslSignNative;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for openssl_sign()/openssl_verify() via OpensslSignJitHelper PHP (#3324, #16454).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\openssl\OpensslSignJitHelper}; thin LLVM
 * bridges forward the ABI. SSOT: {@see \PHPCompiler\ext\openssl\VmOpensslSignNative}
 * php-src: ext/openssl/openssl.c
 */
final class OpensslSignRuntime
{
    private const HELPER_PATH = '/ext/openssl/OpensslSignJitHelper.php';

    private const SIGN_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslSignJitHelper::signArgv';

    private const VERIFY_HELPER = 'PHPCompiler\\ext\\openssl\\OpensslSignJitHelper::verifyArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::SIGN_HELPER,
        self::VERIFY_HELPER,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_openssl_sign',
        '__compiler_openssl_verify',
    ];

    public static function opensslEvRuntimeAvailable(): bool
    {
        return VmOpensslSignNative::available();
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_openssl_sign');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        if (!self::opensslEvRuntimeAvailable()) {
            self::implementStubBridges($context);
        } else {
            self::ensureJitHelperCompiled($context);
            self::implementIfMissing($context, '__compiler_openssl_sign', self::implementSignBridge(...));
            self::implementIfMissing($context, '__compiler_openssl_verify', self::implementVerifyBridge(...));
        }
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

    private static function implementSignBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_sign_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::SIGN_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $raw)
        );
    }

    private static function implementVerifyBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ossl_verify_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::VERIFY_HELPER),
            [$fn->getParam(0), $fn->getParam(1), $fn->getParam(2), $fn->getParam(3)]
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::coerceBridgeResult($context, $raw, $context->getTypeFromString('int32'))
        );
    }

    private static function implementStubBridges(Context $context): void
    {
        self::implementIfMissing($context, '__compiler_openssl_sign', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('ossl_sign_stub_entry');
            $context->builder->positionAtEnd($entry);
            $strPtr = $context->getTypeFromString('__string__*');
            $context->builder->returnValue($strPtr->constNull());
        });
        self::implementIfMissing($context, '__compiler_openssl_verify', static function (Context $context, LlvmFunction $fn): void {
            $entry = $fn->appendBasicBlock('ossl_verify_stub_entry');
            $context->builder->positionAtEnd($entry);
            $i32 = $context->getTypeFromString('int32');
            $context->builder->returnValue($i32->constInt(-1, true));
        });
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after OpensslSignJitHelper compile (#16454)');
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
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'OpensslSignJitHelper.php');
            if (null === $block) {
                throw new \LogicException('OpensslSignJitHelper.php parseAndCompile failed (#16454)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT openssl sign (#16454)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after OpensslSignRuntime bridge (#16454)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
