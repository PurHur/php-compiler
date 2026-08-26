<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AOT: Class::$runtimeMethod() must compile and match Zend (#34937).
 *
 * php-src: Zend/zend_execute.c — ZEND_INIT_STATIC_METHOD_CALL
 *
 * @group llvm
 * @group aot
 */
final class ClassRuntimeMethodStaticCall34937AotTest extends TestCase
{
    public function testNamedClassRuntimeMethodMatchesZend(): void
    {
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_34937_class_runtime_method_static_call.php'
        );
    }

    public function testRuntimeVariableStaticMethodCallClassExists(): void
    {
        $this->assertTrue(class_exists(\PHPCompiler\JIT\Call\RuntimeVariableStaticMethodCall::class));
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $this->assertStringContainsString('#34937', $source);
        $this->assertStringContainsString('RuntimeVariableStaticMethodCall', $source);
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
        $bin = sys_get_temp_dir().'/crm_34937_'.getmypid().'_'.md5($src);
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
