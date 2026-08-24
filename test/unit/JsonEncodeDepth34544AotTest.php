<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: json_encode() honors $depth — JSON_ERROR_DEPTH like Zend (#34544).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeDepth34544AotTest extends TestCase
{
    public function testJsonEncodeDepthMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34544_json_encode_depth_aot.php');
    }

    public function testDepthGlobalsAndEnterInArrayLlvm(): void
    {
        $root = dirname(__DIR__, 2);
        $depth = (string) file_get_contents($root.'/lib/JIT/JsonEncodeDepthLlvm.php');
        $array = (string) file_get_contents($root.'/lib/JIT/JsonEncodeArrayLlvm.php');
        $encode = (string) file_get_contents($root.'/ext/standard/json_encode.php');
        $this->assertStringContainsString('#34544', $depth);
        $this->assertStringContainsString('tryEnter', $array);
        $this->assertStringContainsString('JsonEncodeDepthLlvm::resetForEncode', $encode);
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
        $bin = sys_get_temp_dir().'/json_34544_'.getmypid().'_'.md5($src);
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
