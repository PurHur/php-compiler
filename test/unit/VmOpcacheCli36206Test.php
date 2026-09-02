<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * #36206: CLI opcache bootstrap in php-env.sh and BaseTest::phpCommand().
 */
final class VmOpcacheCli36206Test extends TestCase
{
    public function testPhpEnvEnablesCliOpcacheFileCache(): void
    {
        $body = (string) file_get_contents(dirname(__DIR__, 2).'/script/php-env.sh');
        $this->assertStringContainsString('opcache.enable_cli=1', $body);
        $this->assertStringContainsString('opcache.file_cache=', $body);
        $this->assertStringContainsString('opcache.validate_timestamps=0', $body);
        $this->assertStringContainsString('PHP_COMPILER_OPCACHE_CLI', $body);
        $this->assertStringContainsString('build/opcache-', $body);
    }

    public function testBaseTestPhpCommandIncludesOpcacheWhenEnabled(): void
    {
        putenv('PHP_COMPILER_OPCACHE_CLI=1');
        $dummy = new class extends BaseTest {
            public function exposePhpCommand(): array
            {
                return $this->phpCommand();
            }
        };
        $cmd = $dummy->exposePhpCommand();
        $joined = implode(' ', $cmd);
        $this->assertStringContainsString('opcache.enable_cli=1', $joined);
        $this->assertStringContainsString('opcache.file_cache=', $joined);
        $this->assertStringContainsString('opcache.validate_timestamps=0', $joined);
    }

    public function testBaseTestPhpCommandSkipsOpcacheWhenDisabled(): void
    {
        putenv('PHP_COMPILER_OPCACHE_CLI=0');
        $dummy = new class extends BaseTest {
            public function exposePhpCommand(): array
            {
                return $this->phpCommand();
            }
        };
        $cmd = $dummy->exposePhpCommand();
        $joined = implode(' ', $cmd);
        $this->assertStringNotContainsString('opcache.enable_cli', $joined);
    }
}
