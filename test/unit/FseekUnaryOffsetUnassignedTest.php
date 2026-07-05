<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** fseek($f, -N, SEEK_END) without assigning return — ftell/fgetc must work (#16523). */
final class FseekUnaryOffsetUnassignedTest extends TestCase
{
    public function testFseekNegativeSeekEndOffsetWithoutAssignment(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/maintainer_gap_var_dump_ftell_fgetc_regression.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertSame("int(2)\nstring(1) \"c\"\n", $out);
    }

    public function testFseekInlineUserFnArgStillWorks(): void
    {
        $root = dirname(__DIR__, 2);
        $path = $root.'/test/repro/maintainer_gap_fseek_inline_user_fn_arg.php';
        $cmd = 'php '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($path).' 2>/dev/null';
        $out = shell_exec($cmd);
        self::assertSame("0\n", $out);
    }
}
