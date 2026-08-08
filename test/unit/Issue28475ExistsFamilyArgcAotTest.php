<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: *_exists excess/missing argc → ArgumentCountError at runtime (#28475).
 *
 * php-src: Zend/zend_builtin_functions.stub.php
 *
 * @group llvm
 * @group aot
 */
final class Issue28475ExistsFamilyArgcAotTest extends TestCase
{
    public function testAotValidArityStillWorks(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_28475_ok_'.getmypid().'.php';
        file_put_contents($src, <<<'PHP'
<?php
echo function_exists('strlen') ? '1' : '0', "\n";
echo class_exists('stdClass') ? '1' : '0', "\n";
echo interface_exists('Traversable') ? '1' : '0', "\n";
PHP);
        $bin = sys_get_temp_dir().'/phpc_28475_ok_'.getmypid().'.bin';
        try {
            $compile = 'PHP_COMPILER_HELPER_RUNTIME_O=0 '.escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame("1\n1\n1\n", implode("\n", $runOut)."\n");
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
        $src = sys_get_temp_dir().'/phpc_28475_ex_'.getmypid().'.php';
        foreach ([
            'function_exists' => [
                'code' => "<?php\nfunction_exists();\n",
                'needle' => 'function_exists() expects exactly 1 argument, 0 given',
            ],
            'function_exists_2' => [
                'code' => "<?php\nfunction_exists('strlen', 'x');\n",
                'needle' => 'function_exists() expects exactly 1 argument, 2 given',
            ],
            'class_exists' => [
                'code' => "<?php\nclass_exists();\n",
                'needle' => 'class_exists() expects at least 1 argument, 0 given',
            ],
            'class_exists_3' => [
                'code' => "<?php\nclass_exists('stdClass', true, 'x');\n",
                'needle' => 'class_exists() expects at most 2 arguments, 3 given',
            ],
            'interface_exists' => [
                'code' => "<?php\ninterface_exists();\n",
                'needle' => 'interface_exists() expects at least 1 argument, 0 given',
            ],
            'trait_exists' => [
                'code' => "<?php\ntrait_exists();\n",
                'needle' => 'trait_exists() expects at least 1 argument, 0 given',
            ],
            'enum_exists' => [
                'code' => "<?php\nenum_exists();\n",
                'needle' => 'enum_exists() expects at least 1 argument, 0 given',
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
