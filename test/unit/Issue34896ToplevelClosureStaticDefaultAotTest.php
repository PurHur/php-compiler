<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * #34896 — top-level closure/arrow reading a class static default must match Zend under AOT.
 */
final class Issue34896ToplevelClosureStaticDefaultAotTest extends TestCase
{
    public function testToplevelClosureReadsStaticDefault(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34896_toplevel_closure_static_default.php');
    }

    public function testToplevelArrowReadsStaticDefault(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34896_toplevel_arrow_static_default.php');
    }

    public function testMethodReturnedThrowClosureStillWorks(): void
    {
        // Regression guard for #34868 — precompile still required on method bodies.
        $src = sys_get_temp_dir().'/phpc_34896_throw_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
class C34896Throw {
    public static function m() {
        return fn() => throw new Exception("e");
    }
}
try { C34896Throw::m()(); } catch (Exception $e) { echo $e->getMessage(), "\n"; }
PHP);
        try {
            $this->assertAotMatchesZend($src, "e\n");
        } finally {
            @unlink($src);
        }
    }

    private function assertAotMatchesZend(string $src, ?string $expect = null): void
    {
        $zend = shell_exec('php '.escapeshellarg($src).' 2>&1');
        self::assertNotNull($zend);
        if (null !== $expect) {
            self::assertSame($expect, $zend);
        }

        $bin = sys_get_temp_dir().'/phpc_34896_'.sha1($src).'_'.getmypid().'.bin';
        $cmd = 'php bin/compile.php -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($cmd, $out, $rc);
        self::assertSame(0, $rc, "compile failed:\n".implode("\n", $out));
        self::assertFileExists($bin);

        $aot = shell_exec(escapeshellarg($bin).' 2>&1');
        @unlink($bin);
        self::assertSame($zend, $aot);
    }
}
