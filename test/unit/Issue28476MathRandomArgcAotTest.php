<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: math/CSPRNG/password excess argc → ArgumentCountError at runtime (#28476).
 *
 * php-src: ext/standard/math.stub.php, random.stub.php, password.c
 *
 * @group llvm
 * @group aot
 */
final class Issue28476MathRandomArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28476_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo (string) ceil(1.2), "\n";
echo (string) floor(1.8), "\n";
echo (string) bindec('101'), "\n";
echo (string) hexdec('a'), "\n";
echo password_verify('x', 'y') ? '1' : '0', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28476_ok_'.getmypid().'.bin';
        try {
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("2\n1\n5\n10\n0\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }

    public function testAotWrongArgcRaisesArgumentCountError(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28476_ex_'.getmypid().'.php';
        foreach ([
            'ceil' => [
                'code' => "<?php\nceil(1.0, 2.0);\n",
                'needle' => 'ceil() expects exactly 1 argument, 2 given',
            ],
            'floor' => [
                'code' => "<?php\nfloor();\n",
                'needle' => 'floor() expects exactly 1 argument, 0 given',
            ],
            'bindec' => [
                'code' => "<?php\nbindec('1', 'x');\n",
                'needle' => 'bindec() expects exactly 1 argument, 2 given',
            ],
            'hexdec' => [
                'code' => "<?php\nhexdec();\n",
                'needle' => 'hexdec() expects exactly 1 argument, 0 given',
            ],
            'random_bytes' => [
                'code' => "<?php\nrandom_bytes(1, 2);\n",
                'needle' => 'random_bytes() expects exactly 1 argument, 2 given',
            ],
            'random_int' => [
                'code' => "<?php\nrandom_int(1, 2, 3);\n",
                'needle' => 'random_int() expects exactly 2 arguments, 3 given',
            ],
            'password_verify' => [
                'code' => "<?php\npassword_verify('a');\n",
                'needle' => 'password_verify() expects exactly 2 arguments, 1 given',
            ],
        ] as $name => $case) {
            $s = $src.'.'.$name.'.php';
            $b = $src.'.'.$name.'.bin';
            file_put_contents($s, $case['code']);
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($b).' '.escapeshellarg($s).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, $name.' compile: '.implode("\n", $compileOut));
            $this->assertFileExists($b, $name);
            try {
                $runOut = [];
                exec(escapeshellarg($b).' 2>&1', $runOut, $runRc);
                $this->assertNotSame(0, $runRc, $name.' should abort');
                $joined = implode("\n", $runOut);
                $this->assertStringContainsString($case['needle'], $joined, $name);
                $this->assertStringContainsString('ArgumentCountError', $joined, $name);
                $this->assertStringNotContainsString('LogicException', $joined, $name);
                $this->assertStringNotContainsString('requires exactly', $joined, $name);
            } finally {
                @unlink($s);
                @unlink($b);
            }
        }
        @unlink($src);
    }
}
