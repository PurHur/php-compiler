<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: caller JSON_FORCE_OBJECT must reach nested arrays (#34559); object overlay must not (#34522).
 *
 * @group llvm
 * @group aot
 */
final class JsonEncodeForceObjectNested34559AotTest extends TestCase
{
    public function testJsonEncodeForceObjectNestedMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34559_json_force_object_nested_aot.php');
    }

    public function testChildFlagsStripContainerOverlayOnly(): void
    {
        $root = dirname(__DIR__, 2);
        $llvm = (string) file_get_contents($root.'/lib/JIT/JsonEncodeArrayLlvm.php');
        $this->assertStringContainsString('#34559', $llvm);
        $this->assertStringContainsString('CONTAINER_AS_OBJECT', $llvm);
        $this->assertStringContainsString('~VmJsonFlags::CONTAINER_AS_OBJECT', $llvm);
        $this->assertStringNotContainsString('~VmJsonFlags::FORCE_OBJECT', $llvm);
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
        $bin = sys_get_temp_dir().'/json_34559_'.getmypid().'_'.md5($src);
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
