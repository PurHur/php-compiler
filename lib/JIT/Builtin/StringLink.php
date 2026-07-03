<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;

/**
 * JIT/AOT link for link() via LinkJitHelper PHP.
 *
 * Replaces libc linkat(2) LLVM in ext/standard/JitLink.php.
 * SSOT: {@see \PHPCompiler\ext\standard\VmFs::hardLink()}.
 * php-src: ext/standard/link.c — php_link
 */
final class StringLink
{
    private const ABI = '__phpc_jit_link';

    private const HELPER_PATH = '/ext/standard/LinkJitHelper.php';

    private const INVOKE_HELPER = 'PHPCompiler\\ext\\standard\\LinkJitHelper::invokeArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::INVOKE_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function invoke(Context $context, Value $target, Value $link): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction(self::ABI), $target, $link);
    }

    private static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::ABI, $probe);

            return;
        }

        JitVmHelperLink::ensureCompiled($context, self::HELPER_PATH, self::COMPILED_HELPERS, 'link-jit-php');

        $strPtr = $context->getTypeFromString('__string__*');
        $i1 = $context->getTypeFromString('int1');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                self::ABI,
                $context->context->functionType($i1, false, $strPtr, $strPtr)
            );

        $entry = $fn->appendBasicBlock('link_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $helperFn = JitVmHelperLink::lookupCompiled($context, self::INVOKE_HELPER, 'link-jit-php');
        $raw = JitNestedHelperCoerce::callHelper($context, $helperFn, [$fn->getParam(0), $fn->getParam(1)]);
        $bool = JitNestedHelperCoerce::coerceHelperScalarResult($context, $raw, $i1);
        $context->builder->returnValue($bool);

        $context->registerFunction(self::ABI, $fn);
        $context->builder->clearInsertionPosition();
    }
}
