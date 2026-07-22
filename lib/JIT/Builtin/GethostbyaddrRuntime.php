<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Builder;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for __compiler_gethostbyaddr via GethostbyaddrJitHelper PHP (#9474, #22370).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer CheckdnsrrRuntime #22355).
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#22370');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#22370'
        );
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
