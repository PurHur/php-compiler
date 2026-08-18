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
 * JIT/AOT link for curl_strerror() / curl_multi_strerror() via CurlStrerrorJitHelper PHP (#32352).
 *
 * Helper compile: {@see JitVmHelperLink::ensureBridge} (posix_strerror #12477 /
 * curl_share_strerror #32340 shape).
 * SSOT: {@see \PHPCompiler\ext\curl\CurlStrerrorJitHelper}
 * php-src: ext/curl/interface.c — PHP_FUNCTION(curl_strerror) / curl_multi_strerror
 */
final class CurlStrerrorRuntime
{
    private const ABI_EASY = '__compiler_curl_strerror';

    private const ABI_MULTI = '__compiler_curl_multi_strerror';

    private const HELPER_PATH = '/ext/curl/CurlStrerrorJitHelper.php';

    private const EASY_HELPER = 'PHPCompiler\\ext\\curl\\CurlStrerrorJitHelper::easy';

    private const MULTI_HELPER = 'PHPCompiler\\ext\\curl\\CurlStrerrorJitHelper::multi';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::EASY_HELPER,
        self::MULTI_HELPER,
    ];

    public static function strerror(Context $context, JITVariable $codeArg): Value
    {
        return self::invoke($context, $codeArg, 'curl_strerror(): Argument #1 ($error_code)', self::ABI_EASY);
    }

    public static function multiStrerror(Context $context, JITVariable $codeArg): Value
    {
        return self::invoke($context, $codeArg, 'curl_multi_strerror(): Argument #1 ($error_code)', self::ABI_MULTI);
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

        $probe = $context->module->getNamedFunction(self::ABI_EASY);
        $probeMulti = $context->module->getNamedFunction(self::ABI_MULTI);
        if (null !== $probe && $probe->countBasicBlocks() > 0
            && null !== $probeMulti && $probeMulti->countBasicBlocks() > 0) {
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
            self::ABI_EASY,
            'curl_strerror_bridge_entry',
            [$i64],
            $strPtr,
            self::EASY_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32352'
        );
        JitVmHelperLink::ensureBridge(
            $context,
            self::ABI_MULTI,
            'curl_multi_strerror_bridge_entry',
            [$i64],
            $strPtr,
            self::MULTI_HELPER,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#32352'
        );
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function invoke(
        Context $context,
        JITVariable $codeArg,
        string $argLabel,
        string $abiName
    ): Value {
        self::ensureLinked($context);
        $code = JitLongArg::lower($context, $codeArg, $argLabel);
        $i64 = $context->getTypeFromString('int64');
        $codeI64 = $code->typeOf() === $i64
            ? $code
            : $context->builder->sext($code, $i64);
        $msgStr = $context->builder->call(
            $context->lookupFunction($abiName),
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach ([self::ABI_EASY, self::ABI_MULTI] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after CurlStrerrorRuntime bridge (#32352)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
