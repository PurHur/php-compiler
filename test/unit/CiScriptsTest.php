<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Tiered local CI scripts (issue #436).
 */
final class CiScriptsTest extends TestCase
{
    public function testCiFastScriptExistsAndExcludesLlvmGroup(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $fast = $repoRoot.'/script/ci-fast.sh';
        $this->assertFileExists($fast);
        $this->assertTrue(is_executable($fast));
        $body = (string) file_get_contents($fast);
        $this->assertStringContainsString('--exclude-group llvm', $body);
        $this->assertStringContainsString('ci-local.sh', $body);
    }

    public function testCiLocalRunsPhasedLlvmGroups(): void
    {
        $local = dirname(__DIR__, 2).'/script/ci-local.sh';
        $body = (string) file_get_contents($local);
        $this->assertStringContainsString('--group aot-lint', $body);
        $this->assertStringContainsString('--group jit', $body);
        $this->assertStringContainsString('--group aot-link', $body);
        $this->assertStringContainsString('ci_apply_resource_limits', $body);
        $this->assertStringContainsString('ci_run_examples_web_smoke_aot', $body);
    }

    public function testExamplesWebSmokePrebuildScriptExists(): void
    {
        $prebuild = dirname(__DIR__, 2).'/script/examples-web-smoke-prebuild.sh';
        $this->assertFileExists($prebuild);
        $this->assertTrue(is_executable($prebuild));
        $body = (string) file_get_contents($prebuild);
        $this->assertStringContainsString('001-SimpleWeb', $body);
        $this->assertStringContainsString('phpc build --project', $body);
    }

    public function testCiResourceLimitsDefaultThirtyGib(): void
    {
        $limits = dirname(__DIR__, 2).'/script/ci-resource-limits.sh';
        $body = (string) file_get_contents($limits);
        $this->assertStringContainsString('PHP_COMPILER_CI_RAM_GB:-30}', $body);
    }

    public function testAotLinkGroupTaggedOnAotTest(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__).'/aot/AotTest.php');
        $this->assertStringContainsString('@group aot-link', $source);
    }
}
