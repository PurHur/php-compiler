<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * substr_replace() offset as TYPE_VALUE (Nyholm Stream::$position) (#36382).
 *
 * @group llvm
 */
final class SubstrReplaceValueOffset36382AotTest extends TestCase
{
    public function testAotSubstrReplaceRuntimeIntOffset(): void
    {
        $src = <<<'PHP'
        <?php
        $s = 'abcdef';
        $pos = 2;
        $pos = $pos + 0;
        echo substr_replace($s, 'XY', $pos, 2);
        PHP;
        $path = sys_get_temp_dir().'/phpc_36382_sr_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_36382_sr_'.getmypid().'.bin';
        file_put_contents($path, $src);
        try {
            $cmd = escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>/dev/null', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame(['abXYef'], $runOut);
        } finally {
            @unlink($path);
            @unlink($bin);
        }
    }
}
