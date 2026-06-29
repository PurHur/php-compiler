<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Inline array-returning builtin as non-first user-fn arg (#13637, Zend/zend_execute.c). */
final class InlineArrayBuiltinUserFnArgTest extends TestCase
{
    public function testArrayMergeInlineAsSecondUserFnArg(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/maintainer_gap_array_merge_inline_user_fn_arg.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertSame("ok\n", $out);
    }

    public function testExplodeNegativeLimitInlineUserFnCheckHelper(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/maintainer_gap_explode_negative_limit.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $exit = 0;
        $out = [];
        exec($cmd, $out, $exit);
        self::assertSame(0, $exit, implode("\n", $out));
    }
}
