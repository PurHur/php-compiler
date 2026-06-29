<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** ftell() nested as user-function call argument after fwrite (#13509). */
final class FtellInlineUserFnArgTest extends TestCase
{
    public function testFtellNestedInUserFnAfterFwrite(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/issue_ftell_nested_after_fseek.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertSame("ftell_nested=3\n", $out);
    }

    public function testFwriteRunsBeforeNestedFtellUserFnArg(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/issue_ftell_nested_simple.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertSame("ftell_nested=3\n", $out);
    }
}
