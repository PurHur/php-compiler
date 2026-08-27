<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode() honors JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES (#35339).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodePrettyOrFlags35339AotTest extends TestCase
{
    public function testPrettyPrintOrUnescapedSlashesMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_35339_json_encode_pretty_or_flags_aot.php');
    }

    public function testArrayLiteralFoldRequiresKnownFlags(): void
    {
        $encode = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/json_encode.php');
        $llvm = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/JsonEncodeArrayLlvm.php');
        $this->assertStringContainsString('#35339', $encode);
        $this->assertStringContainsString('null !== $knownFlags', $encode);
        $this->assertStringContainsString('PRETTY_PRINT', $llvm);
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
        $bin = sys_get_temp_dir().'/json_35339_'.getmypid().'_'.md5($src);
        $cmd = 'env PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
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
