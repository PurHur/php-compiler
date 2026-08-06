<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for output_add_rewrite_var / output_reset_rewrite_vars (#9477, #9753, #27566).
 */
final class RewriteVarsRuntime
{
    private const HELPER_PATH = '/ext/standard/OutputRewriteVarsJitHelper.php';

    private const ADD_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::add';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::reset';

    private const EXPORT_HELPER = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::exportBlob';

    private const SET_TAGS = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::setTags';

    private const GET_TAGS = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getTags';

    private const SET_HOSTS = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::setHosts';

    private const GET_HOSTS = 'PHPCompiler\\ext\\standard\\OutputRewriteVarsJitHelper::getHosts';

    /** @var list<string> */
    private const OUTPUT_REWRITE_HELPERS = [
        self::ADD_HELPER,
        self::RESET_HELPER,
        self::EXPORT_HELPER,
        self::SET_TAGS,
        self::GET_TAGS,
        self::SET_HOSTS,
        self::GET_HOSTS,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureBlobHelpers($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureBlobHelpers($context);
    }

    public static function emitAdd(Context $context, Value $nameStr, Value $valueStr): Value
    {
        // NestedJIT full ObOutput+flush into this module BEFORE any helper-cache ObOutput
        // bind — otherwise startWithUrlRewriter and getLevel split across TUs (#27566).
        ObOutputJitBridge::ensureUrlRewriterStack($context);
        self::ensureBlobHelpers($context);
        $context->builder->call($context->lookupFunction('__phpc_ob_start_with_url_rewriter'));
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
        self::ensureBlobHelpers($context);
        $context->builder->call(self::helperFunction($context, self::RESET_HELPER));
        $i1 = $context->getTypeFromString('int1');

        return $i1->constInt(1, false);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureBlobHelpers($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after OutputRewriteVarsJitHelper compile (#9477)');
        }

        return $fn;
    }

    private static function ensureBlobHelpers(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::OUTPUT_REWRITE_HELPERS,
            '#21968'
        );
    }
}
