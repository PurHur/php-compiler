<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** PHPCfg spine parse regression guard (#2575). */
final class BootstrapSpinePhpcfgParseCheckTest extends TestCase
{
    public function testCompilerMinimalSpineParsesUnderPhpcfg(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/bootstrap-spine-php-cfg-parse-check.php')
            .' --minimal 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertStringContainsString('bootstrap-spine-php-cfg-parse-check: OK', implode("\n", $out));
    }

    public function testJitPhpParsesUnderPhpcfg(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/script/bootstrap-spine-php-cfg-parse-check.php')
            .' --file lib/JIT.php 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
