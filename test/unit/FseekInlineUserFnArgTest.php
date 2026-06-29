<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** fseek() negative offset as non-first inline user-function argument (#13451). */
final class FseekInlineUserFnArgTest extends TestCase
{
    public function testFseekNegativeOffsetAsSecondUserFnArg(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/maintainer_gap_fseek_inline_user_fn_arg.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertSame("0\n", $out);
    }
}
