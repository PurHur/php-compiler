<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cross-call typed-null `??` leaves nested typed props uninitialized under AOT (#36382).
 * Slim AppFactory→App→RouteResolver shape; instanceof arm is the fixture workaround.
 *
 * @group unit
 * @group llvm
 */
final class Issue36382CrossCallNullCoalesceAotTest extends TestCase
{
    private function compileAndRun(string $srcRel): string
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/'.$srcRel;
        $bin = sys_get_temp_dir().'/issue_36382_xc_'.getmypid().'_'.md5($srcRel);
        $compile = sprintf(
            '%s -d memory_limit=512M -d opcache.enable_cli=0 %s/bin/compile.php --no-cache -o %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($compile, $out, $cc);
        $this->assertSame(0, $cc, implode("\n", $out));
        $this->assertFileExists($bin);
        try {
            exec(escapeshellarg($bin).' 2>&1', $runOut, $rc);
            $this->assertSame(0, $rc, implode("\n", $runOut));

            return implode("\n", $runOut)."\n";
        } finally {
            @unlink($bin);
        }
    }

    public function testCrossCallNullCoalesceUninitializedWithoutInstanceof(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_cross_call_null_coalesce.php';
        $bin = sys_get_temp_dir().'/issue_36382_xc_bad_'.getmypid();
        $compile = sprintf(
            '%s -d memory_limit=512M -d opcache.enable_cli=0 %s/bin/compile.php --no-cache -o %s %s 2>&1',
            escapeshellarg(PHP_BINARY),
            escapeshellarg($root),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($compile, $out, $cc);
        $this->assertSame(0, $cc, implode("\n", $out));
        exec(escapeshellarg($bin).' 2>&1', $runOut, $rc);
        @unlink($bin);
        $text = implode("\n", $runOut);
        $this->assertNotSame(0, $rc, 'coalesce cross-call should fail under AOT without instanceof');
        $this->assertStringContainsString('must not be accessed before initialization', $text);
    }

    public function testCrossCallNullInstanceofConstructs(): void
    {
        $this->assertSame(
            "inner\nok\n",
            $this->compileAndRun('test/repro/issue_36382_cross_call_null_instanceof.php')
        );
    }
}
