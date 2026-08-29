<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression: Dom\XPath::evaluate on living documents — AOT returned NULL (#21271).
 *
 * @group llvm
 * @group aot
 */
final class DomXPathLivingEvaluateAotTest extends TestCase
{
    public function testLivingStringNumberSumAfterCreateFromString(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/maintainer_gap_dom_xpath_living_string_value.php';
        $this->assertFileExists($src);

        $bin = sys_get_temp_dir().'/phpc_dom_xpath_living_eval_'.getmypid();
        $env = 'PHP_COMPILER_PROFILE=8.4 ';
        $compile = $env.escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $cout, $crc);
        $this->assertSame(0, $crc, "AOT compile failed:\n".implode("\n", $cout));

        try {
            $aot = [];
            exec($env.escapeshellarg($bin).' 2>&1', $aot, $arc);
            $this->assertSame(0, $arc, "AOT run rc=$arc out=".implode("\n", $aot));
            $text = implode("\n", $aot);
            $this->assertStringContainsString('string_na=1', $text);
            $this->assertStringContainsString('string_b=2', $text);
            $this->assertStringContainsString('number_na=1', $text);
            $this->assertStringContainsString('sum_b=2', $text);
            $this->assertStringContainsString('legacy_ok', $text);
        } finally {
            @unlink($bin);
        }
    }
}
