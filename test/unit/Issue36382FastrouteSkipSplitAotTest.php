<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — FastRoute Std::parse preg_split (*SKIP)(*F)|\[ under AOT.
 * php-src: ext/pcre/php_pcre.c — PCRE verbs; Slim AppFactory::create → RouteCollector → RouteParser.
 */
final class Issue36382FastrouteSkipSplitAotTest extends TestCase
{
    public function testAotPregSplitMatchesZend(): void
    {
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_36382_fastroute_skip_split.php';
        $bin = sys_get_temp_dir().'/issue_36382_fastroute_skip_'.getmypid();
        $compile = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src).' 2>&1';
        exec($compile, $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
        @unlink($bin);
        $this->assertSame(0, $runRc, implode("\n", $runOut));
        $text = implode("\n", $runOut);
        $this->assertStringContainsString('parts=1:/hello', $text);
        $this->assertStringContainsString('opt=/hello|/{id}]', $text);
        $this->assertStringContainsString('OK', $text);
    }
}
