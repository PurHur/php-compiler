<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;

/** JIT/AOT link hook for getimagesize*() — compiles GetimagesizeJitHelper into the module (#3271). */
final class GetimagesizeJit
{
    private const HELPER_LOGICAL = 'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::fromBytes';
    private const SHOULD_NOTICE_PATH_LOGICAL = 'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::shouldEmitReadNoticeForPath';
    private const SHOULD_NOTICE_BYTES_LOGICAL = 'PHPCompiler\\ext\\standard\\GetimagesizeJitHelper::shouldEmitReadNoticeForBytes';

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);

        return self::lookupHelper($context, self::HELPER_LOGICAL);
    }

    public static function shouldNoticeForPathHelper(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);

        return self::lookupHelper($context, self::SHOULD_NOTICE_PATH_LOGICAL);
    }

    public static function shouldNoticeForBytesHelper(Context $context): \PHPLLVM\Value\Function_
    {
        self::ensureJitHelperCompiled($context);

        return self::lookupHelper($context, self::SHOULD_NOTICE_BYTES_LOGICAL);
    }

    private static function lookupHelper(Context $context, string $logical): \PHPLLVM\Value\Function_
    {
        $lc = strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after compile (#3271)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $lc = strtolower(self::HELPER_LOGICAL);
        if (isset($context->functions[$lc])) {
            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $runtime = $context->runtime;
        $path = dirname(__DIR__, 3).'/ext/standard/GetimagesizeJitHelper.php';
        $block = $runtime->parseAndCompile((string) file_get_contents($path), 'GetimagesizeJitHelper.php');
        if (null === $block) {
            throw new \LogicException('GetimagesizeJitHelper.php parseAndCompile failed (#3271)');
        }
        $jit = new JIT($context);
        $jit->compile($block);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException('GetimagesizeJitHelper::fromBytes was not compiled for JIT (#3271)');
        }
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
