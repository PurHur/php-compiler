<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #13450 — inline negative-offset builtins as non-first user-fn args. */
final class NegativeOffsetInlineUserFnTest extends TestCase
{
    public function testReproExitsZeroOnVm(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/maintainer_gap_negative_offset_inline_user_fn_arg.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertSame('ok', trim((string) $out));
    }
}
