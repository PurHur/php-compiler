<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** @group unit */
final class CompiledCliArgvEnvBridgeTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_CLI_ARGC');
        for ($i = 0; $i < 8; ++$i) {
            putenv('PHP_COMPILER_CLI_ARGV_'.$i);
        }

        parent::tearDown();
    }

    public function testArgvFromEnvRebuildsList(): void
    {
        require_once dirname(__DIR__, 2).'/src/cli_driver.php';

        putenv('PHP_COMPILER_CLI_ARGC=3');
        putenv('PHP_COMPILER_CLI_ARGV_0=driver');
        putenv('PHP_COMPILER_CLI_ARGV_1=-o');
        putenv('PHP_COMPILER_CLI_ARGV_2=out.php');

        $argv = \php_compiler_cli_argv_from_env();
        $this->assertIsArray($argv);
        $this->assertSame(['driver', '-o', 'out.php'], $argv);
    }

    public function testArgvFromEnvReturnsNullWhenMissing(): void
    {
        require_once dirname(__DIR__, 2).'/src/cli_driver.php';

        $this->assertNull(\php_compiler_cli_argv_from_env());
    }
}
