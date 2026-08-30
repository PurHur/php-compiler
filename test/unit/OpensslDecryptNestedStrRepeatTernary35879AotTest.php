<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Nested str_repeat key/iv into openssl_decrypt must not both bind the IV when a later ?: follows (#35879).
 *
 * @group llvm
 */
final class OpensslDecryptNestedStrRepeatTernary35879AotTest extends TestCase
{
    public function testNestedStrRepeatDecryptWithTernaryMatchesZend(): void
    {
        $src = __DIR__.'/../repro/aot_openssl_decrypt_nested_str_repeat_ternary.php';
        $zend = $this->runPhp($src);
        $vm = $this->runVm($src);
        $aot = $this->runAot($src);
        $this->assertSame("hi\n'hi'\n", $zend);
        $this->assertSame($zend, $vm);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out).([] === $out ? '' : "\n");
    }

    private function runVm(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/vm.php').' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out).([] === $out ? '' : "\n");
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/openssl_decrypt_35879_'.getmypid();
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        putenv('PHP_COMPILER_HELPER_RUNTIME_O=0');
        exec($cmd.' 2>&1', $compOut, $compRc);
        $this->assertSame(0, $compRc, implode("\n", $compOut));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $out, $rc);
        @unlink($bin);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out).([] === $out ? '' : "\n");
    }
}
