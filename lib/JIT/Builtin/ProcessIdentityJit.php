<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPCompiler\JIT\ScriptMagic;
use PHPCompiler\OpCode;
use PHPLLVM\Value;

/** JIT/AOT link for getmypid/getmyuid/getmygid/get_current_user via ProcessIdentityJitHelper PHP (#9017). */
final class ProcessIdentityJit
{
    private const HELPER_PATH = '/ext/standard/ProcessIdentityJitHelper.php';

    private const GETMYPID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmypid';

    private const GETMYUID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmyuid';

    private const GETMYGID = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetmygid';

    private const GET_CURRENT_USER = 'PHPCompiler\\ext\\standard\\ProcessIdentityJitHelper::resolveGetCurrentUser';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GETMYPID,
        self::GETMYUID,
        self::GETMYGID,
        self::GET_CURRENT_USER,
    ];

    public static function getmypid(Context $context): Value
    {
        return self::callIntHelper($context, self::GETMYPID);
    }

    public static function getmyuid(Context $context): Value
    {
        return self::callIntHelper($context, self::GETMYUID);
    }

    public static function getmygid(Context $context): Value
    {
        return self::callIntHelper($context, self::GETMYGID);
    }

    public static function getCurrentUser(Context $context): Value
    {
        self::ensureJitHelperCompiled($context);
        $helperFn = self::helperFunction($context, self::GET_CURRENT_USER);
        $path = '';
        $block = $context->jitEnclosingBlock;
        if ($block instanceof Block) {
            $path = ScriptMagic::stringForBlock($block, OpCode::SCRIPT_MAGIC_FILE);
        }
        $pathStr = $context->builder->load($context->constantStringFromString($path));
        $nameStr = $context->builder->call($helperFn, $pathStr);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $nameStr
        );

        return $ptr;
    }

    private static function callIntHelper(Context $context, string $logical): Value
    {
        self::ensureJitHelperCompiled($context);
        $helperFn = self::helperFunction($context, $logical);
        $raw = $context->builder->call($helperFn);
        $i64 = $context->getTypeFromString('int64');

        return $raw->typeOf() === $i64
            ? $raw
            : $context->builder->zExt($raw, $i64);
    }

    private static function helperFunction(Context $context, string $logical): \PHPLLVM\Value\Function_
    {
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after ProcessIdentityJitHelper compile (#9017)');
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
                $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'ProcessIdentityJitHelper.php');
                if (null === $block) {
                    throw new \LogicException('ProcessIdentityJitHelper.php parseAndCompile failed (#9017)');
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
                throw new \LogicException($lc.' was not compiled for JIT (#9017)');
            }
        }
    }
}
