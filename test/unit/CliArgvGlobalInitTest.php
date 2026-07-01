<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** CliArgvGlobalInit LLVM globals are scoped per JIT module (#14470). */
final class CliArgvGlobalInitTest extends TestCase
{
    public function testCliArgvGlobalInitGuardsPerModule(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/CliArgvGlobalInit.php');
        $this->assertStringContainsString('$module', $source);
        $this->assertStringContainsString('getNamedGlobal', $source);
        $this->assertStringContainsString('self::$module === $module', $source);
    }
}
