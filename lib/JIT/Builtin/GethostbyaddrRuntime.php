<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gethostbyaddr via GethostbyaddrJitHelper PHP (#9474).
 *
 * Embed and standalone AOT compile the same PHP bridge; no libc gethostbyaddr LLVM (#13195).
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbyaddr)
 */
final class GethostbyaddrRuntime
{
    private const HELPER_PATH = '/ext/standard/GethostbyaddrJitHelper.php';

    private const RESOLVE_HELPER = 'PHPCompiler\\ext\\standard\\GethostbyaddrJitHelper::resolve';

    private const ABI_NAME = '__compiler_gethostbyaddr';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESOLVE_HELPER,
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
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureJitHelperCompiled($context);
        self::implementResolveBridge($context);
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    private static function implementResolveBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_NAME);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI_NAME, $probe);

            return;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $strPtr);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_NAME, $ft);

        $entry = $fn->appendBasicBlock('ghba_bridge_entry');
        $invalidBb = $fn->appendBasicBlock('ghba_bridge_invalid');
        $resolveBb = $fn->appendBasicBlock('ghba_bridge_resolve');
        $failBb = $fn->appendBasicBlock('ghba_bridge_fail');
        $okBb = $fn->appendBasicBlock('ghba_bridge_ok');

        $context->builder->positionAtEnd($entry);
        $ip = $fn->getParam(0);
        $nullIp = $context->builder->icmp(Builder::INT_EQ, $ip, $strPtr->constNull());
        $context->builder->branchIf($nullIp, $invalidBb, $resolveBb);

        $context->builder->positionAtEnd($invalidBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($resolveBb);
        $hostStr = $context->builder->call(
            self::helperFunction($context, self::RESOLVE_HELPER),
            $ip
        );
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($hostStr, $map['length']));
        $empty = $context->builder->icmp(
            Builder::INT_EQ,
            $len,
            $i64->constInt(0, false)
        );
        $context->builder->branchIf($empty, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($strPtr->constNull());

        $context->builder->positionAtEnd($okBb);
        $context->builder->returnValue($hostStr);
        $context->registerFunction(self::ABI_NAME, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after GethostbyaddrJitHelper compile (#9474)');
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
        $realPath = \realpath($path) ?: $path;
        $prevSelfHostAot = \getenv('PHP_COMPILER_SELFHOST_AOT');
        if (\function_exists('putenv')) {
            \putenv('PHP_COMPILER_SELFHOST_AOT=0');
        }
        try {
            NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path, $realPath): void {
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'GethostbyaddrJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('GethostbyaddrJitHelper.php parseAndCompile failed (#9474)');
                }
                $jit = new JIT($context);
                $jit->compile($block);
                $context->markJitIncludedFileCompiled($realPath);
            });
        } finally {
            if (\function_exists('putenv')) {
                if (false === $prevSelfHostAot || null === $prevSelfHostAot) {
                    \putenv('PHP_COMPILER_SELFHOST_AOT=');
                } else {
                    \putenv('PHP_COMPILER_SELFHOST_AOT='.$prevSelfHostAot);
                }
            }
        }
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9474)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_NAME);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_NAME.' missing after GethostbyaddrRuntime bridge (#9474)');
        }
        $context->registerFunction(self::ABI_NAME, $fn);
    }
}
