<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * In-place `.=` of lit/int concat chains must append leaves (smart_str), not alloc each temp (#36386).
 *
 * php-src: Zend/zend_operators.c ZEND_ASSIGN_CONCAT / zend_string_extend.
 *
 * @group aot-lint
 */
final class JitConcatChainFlattenAotTest extends TestCase
{
    private function compileAndMatchZend(string $src, string $tag): void
    {
        $path = sys_get_temp_dir().'/phpc_concat_'.$tag.'_'.getmypid().'.php';
        $bin = sys_get_temp_dir().'/phpc_concat_'.$tag.'_'.getmypid().'.bin';
        $cache = sys_get_temp_dir().'/phpc_concat_hcache_'.$tag.'_'.getmypid();
        if (!is_dir($cache)) {
            mkdir($cache, 0777, true);
        }
        file_put_contents($path, $src);
        $prev = getenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR');
        putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.$cache);
        $_ENV['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = $cache;
        try {
            exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($path).' 2>&1', $zendOut, $zendRc);
            $this->assertSame(0, $zendRc, implode("\n", $zendOut));
            $cmd = 'PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.escapeshellarg($cache).' '
                .escapeshellarg(PHP_BINARY).' '
                .escapeshellarg(__DIR__.'/../../bin/compile.php').' -o '
                .escapeshellarg($bin).' '.escapeshellarg($path).' 2>&1';
            exec($cmd, $out, $rc);
            $this->assertSame(0, $rc, implode("\n", $out));
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));
            $this->assertSame($zendOut, $runOut);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR');
                unset($_ENV['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR']);
            } else {
                putenv('PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR='.$prev);
                $_ENV['PHP_COMPILER_HELPER_RUNTIME_CACHE_DIR'] = $prev;
            }
            @unlink($path);
            @unlink($bin);
        }
    }

    public function testTemplateShapeMatchesZend(): void
    {
        $this->compileAndMatchZend(<<<'PHP'
<?php
$rows = 200;
$html = '';
for ($i = 0; $i < $rows; ++$i) {
    $html .= '<tr><td>'.$i.'</td><td>name-'.$i.'</td><td>'.($i * 3)."</td></tr>\n";
}
echo strlen($html), '|', substr($html, 0, 24), '|', md5($html), "\n";
PHP, 'flat');
    }

    public function testStrBuilderShapeMatchesZend(): void
    {
        $this->compileAndMatchZend(<<<'PHP'
<?php
$buf = '';
for ($i = 0; $i < 400; ++$i) {
    $buf .= 'row-'.$i.';';
}
echo strlen($buf), '|', substr($buf, 0, 12), '|', md5($buf), "\n";
PHP, 'sb');
    }

    public function testHelpersPresent(): void
    {
        $jit = (string) file_get_contents(__DIR__.'/../../lib/JIT.php');
        $str = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/Type/String_.php');
        $this->assertStringContainsString('tryCompileConcatChainFlatten', $jit);
        $this->assertStringContainsString('appendInPlaceLong', $str);
        $this->assertStringContainsString('appendInPlaceBytes', $str);
    }
}
