<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;

/** JIT/AOT link hook for mb_strwidth() / mb_strimwidth() — compiles MbStrwidthJitHelper (#3495). */
final class MbStrwidth
{
    private const HELPER_STRWIDTH = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strwidth';
    private const HELPER_STRIMWIDTH = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strimwidth';
    private const HELPER_STRPAD = 'PHPCompiler\\ext\\mbstring\\MbStrwidthJitHelper::strPad';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function strwidthFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_STRWIDTH);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('MbStrwidthJitHelper::strwidth missing after compile (#3495)');
        }

        return $fn;
    }

    public static function strimwidthFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_STRIMWIDTH);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('MbStrwidthJitHelper::strimwidth missing after compile (#3495)');
        }

        return $fn;
    }

    public static function strPadFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);
        $lc = strtolower(self::HELPER_STRPAD);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException('MbStrwidthJitHelper::strPad missing after compile (#6081)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lcWidth = strtolower(self::HELPER_STRWIDTH);
        if (isset($context->functions[$lcWidth])) {
            return;
        }

        $runtime = $context->runtime;
        $path = dirname(__DIR__, 3).'/ext/mbstring/MbStrwidthJitHelper.php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'MbStrwidthJitHelper.php');
        if (null === $block) {
            throw new \LogicException('MbStrwidthJitHelper.php parseAndCompile failed (#3495)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lcWidth])) {
            throw new \LogicException('MbStrwidthJitHelper::strwidth was not compiled for JIT (#3495)');
        }
    }
}
