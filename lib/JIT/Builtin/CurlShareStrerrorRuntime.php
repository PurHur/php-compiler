<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * JIT/AOT link for curl_share_strerror() via CurlShareStrerrorJitHelper PHP (#32340).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (posix_strerror #12477 shape).
 * SSOT: {@see \PHPCompiler\ext\curl\CurlShareStrerrorJitHelper}
 * php-src: ext/curl/share.c — PHP_FUNCTION(curl_share_strerror)
 */
final class CurlShareStrerrorRuntime
{
    private const ABI_MESSAGE = '__compiler_curl_share_strerror';

    private const HELPER_PATH = '/ext/curl/CurlShareStrerrorJitHelper.php';

    private const MESSAGE_HELPER = 'PHPCompiler\\ext\\curl\\CurlShareStrerrorJitHelper::message';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::MESSAGE_HELPER,
    ];

    public static function strerror(Context $context, JITVariable $codeArg): Value
    {
        self::ensureLinked($context);
        $code = JitLongArg::lower($context, $codeArg, 'curl_share_strerror(): Argument #1 ($error_code)');
        $i64 = $context->getTypeFromString('int64');
        $codeI64 = $code->typeOf() === $i64
            ? $code
            : $context->builder->sext($code, $i64);
        $msgStr = $context->builder->call(
            $context->lookupFunction(self::ABI_MESSAGE),
            $codeI64
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $msgStr
        );

        return $ptr;
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        if (NestedJitCompileScope::isActive()) {
            return;
        }

        $probe = $context->module->getNamedFunction(self::ABI_MESSAGE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_MESSAGE,
            'curl_share_strerror_bridge_entry',
            [$i64],
            $strPtr,
            self::MESSAGE_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32340'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_MESSAGE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_MESSAGE.' missing after CurlShareStrerrorRuntime bridge (#32340)');
        }
        $context->registerFunction(self::ABI_MESSAGE, $fn);
    }
}
