<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @covers issue #9051 */
final class UriExtensionTest extends TestCase
{
    public function testPhantomWithholdOnReferenceProfile(): void
    {
        $path = dirname(__DIR__).'/repro/maintainer_gap_uri_extension.php';
        $output = shell_exec(PHP_BINARY.' bin/vm.php '.escapeshellarg($path).' 2>&1');
        $this->assertIsString($output);
        $this->assertStringContainsString('ok', $output);
    }

    public function testExtensionLoadedAndRfc3986ParseOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $path = dirname(__DIR__).'/repro/maintainer_gap_uri_extension_forward.php';
            $output = shell_exec(PHP_BINARY.' bin/vm.php '.escapeshellarg($path).' 2>&1');
            $this->assertIsString($output);
            $this->assertStringContainsString("true\ntrue", $output);
            $this->assertStringContainsString("'example.com'", $output);
            $this->assertStringContainsString("'/path'", $output);
            $this->assertStringContainsString("'example.org'", $output);
            $this->assertStringContainsString('ok', $output);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
