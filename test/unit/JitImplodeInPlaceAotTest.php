<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * implode() must grow the result with appendInPlace, not alloc+memcpy the accumulator
 * per element (#36386). php-src: ext/standard/string.c php_implode / smart_str_appendl.
 *
 * @group aot-lint
 */
final class JitImplodeInPlaceAotTest extends TestCase
{
    public function testImplodePackedIntsMatchZend(): void
    {
        $srcFile = (string) file_get_contents(__DIR__.'/../../ext/standard/JitImplode.php');
        $this->assertStringContainsString(
            'appendInPlace',
            $srcFile,
            'JitImplode rest path must call String_::appendInPlace (#36386)'
        );
        $src = <<<'PHP'
        <?php
        $parts = [];
        for ($i = 0; $i < 50; ++$i) {
            $parts[] = (string) ($i % 100000);
        }
        $joined = implode(',', $parts);
        echo strlen($joined), '|', $joined, "\n";
        echo implode('-', ['a', 'b']), "\n";
        echo implode(['x', 'y', 'z']), "\n";
        echo implode(',', []), '|', implode(',', ['only']), "\n";
        PHP;
        $path = sys_get_temp_dir().'/phpc_implode_scale_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_implode_scale_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zendOut, $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
