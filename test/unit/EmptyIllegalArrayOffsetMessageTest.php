<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Issue #29567 — empty($arr[[]]) TypeError must match isset wording.
 */
final class EmptyIllegalArrayOffsetMessageTest extends TestCase
{
    private const EXPECTED =
        "TypeError:Illegal offset type in isset or empty\n"
        ."TypeError:Illegal offset type in isset or empty\n";

    private const EXPECTED_84 =
        "TypeError:Cannot access offset of type array in isset or empty\n"
        ."TypeError:Cannot access offset of type array in isset or empty\n";

    public function testEmptyArrayOffsetMessageMatchesIssetViaRuntime(): void
    {
        $code = file_get_contents(dirname(__DIR__).'/repro/empty_arr_key_29567.php');
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'empty_arr_key_29567.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame(self::EXPECTED, $output);
    }

    public function testEmptyArrayOffsetMessageMatchesIssetViaVmBin(): void
    {
        $this->assertBinOutput('bin/vm.php', self::EXPECTED);
    }

    public function testEmptyArrayOffsetMessageMatchesIssetViaJitBin(): void
    {
        $this->assertBinOutput('bin/jit.php', self::EXPECTED);
    }

    public function testProfile84TypedMessageViaVmBin(): void
    {
        $this->assertBinOutput('bin/vm.php', self::EXPECTED_84, '8.4');
    }

    private function assertBinOutput(string $binRel, string $expected, ?string $profile = null): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/empty_arr_key_29567.php';
        $env = '';
        if (null !== $profile) {
            $env = 'PHP_COMPILER_PROFILE='.escapeshellarg($profile).' ';
        }
        $cmd = $env.escapeshellarg(PHP_BINARY).' -d zend.exception_ignore_args=0 '
            .escapeshellarg($root.'/'.$binRel).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $joined = implode("\n", $out)."\n";
        $this->assertStringContainsString($expected, $joined);
    }
}
