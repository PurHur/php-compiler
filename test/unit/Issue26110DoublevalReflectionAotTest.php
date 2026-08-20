<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: doubleval(value:) named call matches Zend (#26110).
 *
 * php-src: ext/standard/type.stub.php / type.c
 *
 * @group llvm
 * @group aot
 */
final class Issue26110DoublevalReflectionAotTest extends TestCase
{
    public function testAotNamedValueCall(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $root = dirname(__DIR__, 2);
        $src = sys_get_temp_dir().'/phpc_26110_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_26110_'.getmypid().'.bin';
        file_put_contents($src, <<<'PHP'
<?php
echo doubleval(value: '3.5'), "\n";
echo doubleval('4.5'), "\n";
PHP);
        try {
            $compile = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg($root.'/bin/compile.php')
                .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
            exec($compile, $compileOut, $compileRc);
            $this->assertSame(0, $compileRc, 'compile: '.implode("\n", $compileOut));
            $this->assertFileExists($bin);
            $runOut = [];
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, 'run: '.implode("\n", $runOut));
            $this->assertSame("3.5\n4.5\n", implode("\n", $runOut)."\n");
        } finally {
            @unlink($src);
            @unlink($bin);
        }
    }
}
