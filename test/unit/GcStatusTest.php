<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** gc_status() / gc_mem_caches() VM introspection (#3280). */
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
echo 'runs=', $st['runs'], "\n";
echo 'threshold=', $st['threshold'], "\n";
PHP;

        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_status_registered.php');
        ob_start();
        $rt->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('gc_status=yes', $output);
        $this->assertStringContainsString('gc_mem_caches=yes', $output);
        $this->assertStringContainsString('runs=0', $output);
        $this->assertStringContainsString('threshold=10001', $output);
    }

    public function testGcStatusPhp84Fields(): void
    {
        $code = <<<'PHP'
<?php
$s = gc_status();
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    echo $key, '=', array_key_exists($key, $s) ? 'yes' : 'no', "\n";
    if (array_key_exists($key, $s)) {
        echo $key, '_type=', is_bool($s[$key]) ? 'bool' : (is_int($s[$key]) ? 'int' : 'other'), "\n";
    }
}
PHP;

        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_status_php84.php');
        ob_start();
        $rt->run($block);
        $output = ob_get_clean();

        foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
            $this->assertStringContainsString($key.'=yes', $output);
        }
        $this->assertStringContainsString('running_type=bool', $output);
        $this->assertStringContainsString('protected_type=bool', $output);
        $this->assertStringContainsString('full_type=bool', $output);
        $this->assertStringContainsString('buffer_size_type=int', $output);
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
$roots = gc_status()['roots'];
$n = gc_collect_cycles();
$st = gc_status();
echo 'roots_before=', $roots, "\n";
echo 'collected=', $n, "\n";
echo 'runs=', $st['runs'], "\n";
echo 'total=', $st['collected'], "\n";
PHP;

        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_status_collect.php');
        ob_start();
        $rt->run($block);
        $output = ob_get_clean();

        $this->assertStringContainsString('roots_before=2', $output);
        $this->assertStringContainsString('collected=2', $output);
        $this->assertStringContainsString('runs=1', $output);
        $this->assertStringContainsString('total=2', $output);
    }
}
