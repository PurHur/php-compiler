<?php

declare(strict_types=1);

namespace Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * @group llvm
 */
final class BootstrapAotEchoCompileTest extends TestCase
{
    public function testEchoHelloBootstrapAotCompileDoesNotSegfault(): void
    {
        $root = \dirname(__DIR__, 2);
        $llvm = $root.'/.llvm';
        if (\is_readable($llvm.'/libLLVM-9.so.1')) {
            \putenv('PHP_COMPILER_LLVM_PATH='.$llvm);
        }
        $source = $root.'/test/bootstrap-aot/echo_hello.php';
        $out = \sys_get_temp_dir().'/phpc_bootstrap_echo_hello_'.getmypid();
        @\unlink($out);

        $cmd = \sprintf(
            '%s %s/bin/compile.php -o %s %s 2>&1',
            \PHP_BINARY,
            $root,
            \escapeshellarg($out),
            \escapeshellarg($source)
        );
        $output = [];
        $code = 0;
        \exec($cmd, $output, $code);

        self::assertSame(
            0,
            $code,
            "bootstrap-aot echo_hello compile must not segfault (#14459)\n".\implode("\n", $output)
        );
        self::assertFileExists($out, 'compile.php must emit an AOT binary');
        @\unlink($out);
    }
}
