<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for output_add_rewrite_var / output_reset_rewrite_vars via OutputRewriteVarsJitHelper PHP (#9477, #9753).
 *
 * JIT embed and AOT standalone compile {@see OutputRewriteVarsJitHelper} static storage.
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer SpaceshipRuntime #21949 / SessionCreateIdRuntime #21941).
 * php-src: ext/standard/url.c — PHP_FUNCTION(output_add_rewrite_var), output_reset_rewrite_vars.
 * VM SSOT: {@see \PHPCompiler\Web\ResponseContext}.
 */
final class RewriteVarsRuntime
{
    private const HELPER_PATH = '/ext/standard/OutputRewriteVarsJitHelper.php';

    private const URL_REWRITER_PATH = '/ext/standard/VmUrlRewriterOb.php';

    private const ADD_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::add';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::reset';

    private const ENSURE_URL_REWRITER = 'PHPCompiler\\ext\\standard\\VmUrlRewriterOb::ensureRegistered';

    private const RESET_URL_REWRITER = 'PHPCompiler\\ext\\standard\\VmUrlRewriterOb::resetState';

    /** @var list<string> */
    private const URL_REWRITER_HELPERS = [
        self::ENSURE_URL_REWRITER,
        self::RESET_URL_REWRITER,
    ];

    /** @var list<string> */
    private const OUTPUT_REWRITE_HELPERS = [
        self::ADD_HELPER,
        self::RESET_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function emitAdd(Context $context, Value $nameStr, Value $valueStr): Value
    {
        self::ensureJitHelperCompiled($context);
        $context->builder->call(
            self::helperFunction($context, self::ADD_HELPER),
            $nameStr,
            $valueStr
        );
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    public static function emitReset(Context $context): Value
    {
        self::ensureJitHelperCompiled($context);
        $context->builder->call(self::helperFunction($context, self::RESET_HELPER));
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after OutputRewriteVarsJitHelper compile (#9477)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        // VmUrlRewriterOb first — OutputRewriteVarsJitHelper::add calls ensureRegistered (#21965/#21968).
        // Scanner/flush stay out of this NestedJIT (VmUrlRewriterFlush / UrlScannerEx, #24370).
        JitVmHelperLink::ensureCompiled(
            $context,
            self::URL_REWRITER_PATH,
            self::URL_REWRITER_HELPERS,
            '#21968'
        );
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::OUTPUT_REWRITE_HELPERS,
            '#21968'
        );
    }
}
