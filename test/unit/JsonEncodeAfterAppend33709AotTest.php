<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode after $a[]= / unset must not fold the pre-mutation INIT_ARRAY (#33709).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeAfterAppend33709AotTest extends TestCase
{
    public function testAppendNullAndUnsetMatchZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_33709_json_encode_after_append.php');
    }

    public function testLiteralStillFoldsAndMatches(): void
    {
        $src = sys_get_temp_dir().'/je_lit_33709_'.getmypid().'.php';
        file_put_contents($src, "<?php echo json_encode([1, null, 2]), PHP_EOL;\n");
        try {
            $this->assertAotMatchesZend($src);
        } finally {
            @unlink($src);
        }
    }

    public function testDimMutationGuardPresent(): void
    {
        $root = dirname(__DIR__, 2);
        $src = (string) file_get_contents($root.'/lib/JIT/CallUnpackCompileTime.php');
        $this->assertStringContainsString('slotHasDimMutation', $src);
        $this->assertStringContainsString('#33709', $src);
    }

    private function assertAotMatchesZend(string $src): void
    {
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out);
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/je_33709_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));

            return implode("\n", $out);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
