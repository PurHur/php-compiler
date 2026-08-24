<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: foreach childNodes must not alias/corrupt live nodeName (#34465 / peer #33849).
 *
 * @group llvm
 * @group aot
 */
final class DomForeachNodeNameAlias34465AotTest extends TestCase
{
    public function testForeachNodeNameAssignMatchesZend(): void
    {
        $this->assertAotMatchesZend(__DIR__.'/../repro/issue_34465_dom_foreach_nodename_alias_aot.php');
    }

    public function testScalarPropertyAliasDetachIsPresent(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('#34465', $src);
        $this->assertStringContainsString('detachScalarObjectPropertyAliasForAssign', $src);
        $this->assertStringContainsString('isScalarObjectPropertyAliasType', $src);
        $llvm = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Builtin/Type/ObjectInstancePropertyLlvm.php'
        );
        $this->assertStringContainsString('#34465', $llvm);
        $this->assertStringContainsString('Read-mode string props: do not alias the live slot', $llvm);
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
        $bin = sys_get_temp_dir().'/ao_34465_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut);
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
