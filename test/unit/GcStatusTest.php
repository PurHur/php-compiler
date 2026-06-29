<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\MemoryAccounting;
use PHPUnit\Framework\TestCase;

/** gc_status() / gc_mem_caches() VM introspection (#3280, #12780, #12790). */
final class GcStatusTest extends TestCase
{
    public function testGcStatusAndMemCachesRegistered(): void
    {
        $code = <<<'PHP'
<?php
foreach (['gc_status', 'gc_mem_caches'] as $fn) {
    echo $fn, '=', function_exists($fn) ? 'yes' : 'no', "\n";
}
$st = gc_status();
gc_mem_caches();
echo 'schema=', array_key_exists('runs', $st) ? 'legacy' : '84', "\n";
if (array_key_exists('runs', $st)) {
    echo 'runs=', $st['runs'], "\n";
    echo 'threshold=', $st['threshold'], "\n";
} else {
    echo 'running=', $st['running'] ? 'true' : 'false', "\n";
    echo 'buffer_size=', $st['buffer_size'], "\n";
}
PHP;

        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_status_registered.php');
        ob_start();
        $rt->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('gc_status=yes', $output);
        $this->assertStringContainsString('gc_mem_caches=yes', $output);
        if (CompilerVersion::supportsGcStatusPhp84Schema()) {
            $this->assertStringContainsString('schema=84', $output);
            $this->assertStringContainsString('running=false', $output);
            $this->assertStringContainsString('buffer_size=131072', $output);
        } else {
            $this->assertStringContainsString('schema=legacy', $output);
            $this->assertStringContainsString('runs=0', $output);
            $this->assertStringContainsString('threshold=10001', $output);
        }
    }

    public function testGcStatusPhpSrcShape(): void
    {
        $code = <<<'PHP'
<?php
$s = gc_status();
ksort($s);
echo implode(',', array_keys($s)), "\n";
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    echo $key, '=', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
    echo $key, '=', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
}
PHP;

        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_status_shape.php');
        ob_start();
        $rt->run($block);
        $output = ob_get_clean();

        if (CompilerVersion::supportsGcStatusPhp84Schema()) {
            $this->assertStringContainsString('buffer_size,full,protected,running', $output);
            foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
                $this->assertStringContainsString($key.'=yes', $output);
            }
            foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
                $this->assertStringContainsString($key.'=no', $output);
            }
        } else {
            $this->assertStringContainsString('collected,roots,runs,threshold', $output);
            foreach (['runs', 'collected', 'threshold', 'roots'] as $key) {
                $this->assertStringContainsString($key.'=yes', $output);
            }
            foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
                $this->assertStringContainsString($key.'=no', $output);
            }
        }
    }

    public function testGcMemCachesReturnsNonZeroOnFirstCall(): void
    {
        $expected = MemoryAccounting::initialMmCache();
        $code = <<<'PHP'
<?php
$first = gc_mem_caches();
$second = gc_mem_caches();
echo 'first=', $first, "\n";
echo 'second=', $second, "\n";
PHP;

        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_mem_caches_return.php');
        ob_start();
        $rt->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('first='.$expected, $output);
        $this->assertStringContainsString('second=0', $output);
    }

    public function testGcStatusRootsZeroAtColdStart(): void
    {
        if (CompilerVersion::supportsGcStatusPhp84Schema()) {
            $this->markTestSkipped('PHP 8.4 gc_status schema has no roots key (#12790)');
        }
        $code = <<<'PHP'
<?php
echo 'roots=', gc_status()['roots'], "\n";
PHP;

        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_status_roots_cold.php');
        ob_start();
        $rt->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('roots=0', $output);
    }

    public function testGcCollectCyclesUpdatesStatus(): void
    {
        $code = <<<'PHP'
<?php
$a = new stdClass();
$b = new stdClass();
$a->b = $b;
$b->a = $a;
unset($a, $b);
$n = gc_collect_cycles();
$st = gc_status();
echo 'collected=', $n, "\n";
echo 'schema=', array_key_exists('runs', $st) ? 'legacy' : '84', "\n";
if (array_key_exists('runs', $st)) {
    echo 'runs=', $st['runs'], "\n";
    echo 'gc_collected=', $st['collected'], "\n";
} else {
    echo 'running=', $st['running'] ? 'true' : 'false', "\n";
    echo 'full=', $st['full'] ? 'true' : 'false', "\n";
}
PHP;

        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_status_collect.php');
        ob_start();
        $rt->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('collected=2', $output);
        if (CompilerVersion::supportsGcStatusPhp84Schema()) {
            $this->assertStringContainsString('schema=84', $output);
            $this->assertStringContainsString('running=false', $output);
            $this->assertStringContainsString('full=false', $output);
        } else {
            $this->assertStringContainsString('schema=legacy', $output);
            $this->assertStringContainsString('runs=1', $output);
            $this->assertStringContainsString('gc_collected=2', $output);
        }
    }
}
