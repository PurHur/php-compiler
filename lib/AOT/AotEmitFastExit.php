<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

/**
 * Skip PHP/LLVM destructor teardown after a successful self-host AOT emit.
 *
 * ExecutionEngine takes module ownership; request shutdown then walks multi-GiB
 * PHPLLVM graphs and can spin at ~100% CPU for 15+ minutes after the -o binary
 * is already linked and runnable (#21925 abort, #31726 hang).
 *
 * {@see warmup()} must run before heavy LLVM FFI use so libc `_exit` is bound
 * without re-parsing cdef against a huge llvm FFI context.
 */
final class AotEmitFastExit
{
    private static ?\FFI $libc = null;

    private static bool $warmupFailed = false;

    /** Bind libc `_exit` early (before LLVM module growth). */
    public static function warmup(): void
    {
        if (null !== self::$libc || self::$warmupFailed) {
            return;
        }
        if (!(\class_exists(\FFI::class, false) || \class_exists(\FFI::class))) {
            self::$warmupFailed = true;

            return;
        }
        $cdef = 'void _exit(int status);';
        foreach (['libc.so.6', 'libc.so'] as $lib) {
            try {
                self::$libc = \FFI::cdef($cdef, $lib);

                return;
            } catch (\Throwable) {
            }
        }
        try {
            // Last resort: default library search (may be slower after LLVM loads).
            self::$libc = \FFI::cdef($cdef);
        } catch (\Throwable) {
            self::$warmupFailed = true;
        }
    }

    /**
     * Exit 0 immediately when self-host AOT emit succeeded.
     *
     * @param string|null $sourceFilename compile entry path (may be absolute)
     * @param string|null $outfile        -o path to assert non-empty before exit
     */
    public static function exitAfterSuccessfulSelfhostEmit(
        ?string $sourceFilename = null,
        ?string $outfile = null
    ): void {
        $noFastExit = getenv('PHP_COMPILER_AOT_NO_FAST_EXIT');
        if ('1' === $noFastExit || 'true' === strtolower((string) $noFastExit)) {
            return;
        }
        $normalized = null !== $sourceFilename && '' !== $sourceFilename
            ? str_replace('\\', '/', $sourceFilename)
            : '';
        $selfhostEnv = getenv('PHP_COMPILER_SELFHOST_AOT');
        $isSelfhostPath = '' !== $normalized && str_contains($normalized, 'test/selfhost/');
        $isSelfhostEnv = '1' === $selfhostEnv || 'true' === strtolower((string) $selfhostEnv);
        // Match #31741: selfhost path or explicit SELFHOST_AOT (compile_driver argv).
        if (!$isSelfhostPath && !$isSelfhostEnv) {
            return;
        }
        if (null !== $outfile && '' !== $outfile) {
            Linker::assertNonEmptyRequestedOutput($outfile);
        }
        self::warmup();
        if (null === self::$libc) {
            return;
        }
        if (\class_exists(\PHPCompiler\JIT\Progress::class, false)) {
            \PHPCompiler\JIT\Progress::noteFunction('runtime_standalone_fast_exit');
        }
        self::$libc->_exit(0);
    }
}
