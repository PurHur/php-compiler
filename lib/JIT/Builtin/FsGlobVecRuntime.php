<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for glob()/scandir() via FsGlobJitHelper PHP (#11515, #12909).
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer SysGetTempDirRuntime #22187 / #29986).
 * Embed + thin standalone AOT compile {@see \PHPCompiler\ext\standard\FsGlobJitHelper}; thin LLVM
 * bridge forwards the ABI. php-src: ext/standard/dir.c
 */
final class FsGlobVecRuntime
{
    private const HELPER_PATH = '/ext/standard/FsGlobJitHelper.php';

    public const GLOB_HELPER = 'PHPCompiler\\ext\\standard\\FsGlobJitHelper::globArgv';

    public const SCANDIR_HELPER = 'PHPCompiler\\ext\\standard\\FsGlobJitHelper::scandirArgv';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::GLOB_HELPER,
        self::SCANDIR_HELPER,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
    }

    public static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after FsGlobJitHelper compile (#11515)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#29986'
        );
    }
}
