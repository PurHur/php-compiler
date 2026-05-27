<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class CompiledCliDriverModeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_CLI_SKIP_VENDOR');
        putenv('PHP_COMPILER_CLI_COMPILED');
        putenv('PHP_COMPILER_SELFHOST_AOT');

        parent::tearDown();
    }

    public function testDefaultsToNotSkippingVendorAutoload(): void
    {
        require_once dirname(__DIR__, 2).'/src/cli.php';

        $this->assertFalse(\php_compiler_cli_should_skip_vendor_autoload());
    }

    public function testSelfhostAotDefaultsToSkippingVendorAutoload(): void
    {
        require_once dirname(__DIR__, 2).'/src/cli.php';

        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $this->assertTrue(\php_compiler_cli_should_skip_vendor_autoload());
    }

    public function testCompiledCliDriverModeSkipsVendorAutoload(): void
    {
        require_once dirname(__DIR__, 2).'/src/cli.php';

        putenv('PHP_COMPILER_CLI_COMPILED=1');
        $this->assertTrue(\php_compiler_cli_should_skip_vendor_autoload());
    }

    public function testExplicitSkipVendorOverrideWins(): void
    {
        require_once dirname(__DIR__, 2).'/src/cli.php';

        putenv('PHP_COMPILER_CLI_COMPILED=1');
        putenv('PHP_COMPILER_CLI_SKIP_VENDOR=0');
        $this->assertFalse(\php_compiler_cli_should_skip_vendor_autoload());
    }
}

