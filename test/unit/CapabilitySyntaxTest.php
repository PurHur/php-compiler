<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Language-construct capability matrix (issue #611).
 */
final class CapabilitySyntaxTest extends TestCase
{
    public function testCapabilitySyntaxScriptExists(): void
    {
        $script = dirname(__DIR__, 2).'/script/capability-syntax.php';
        $this->assertFileExists($script);
    }

    public function testCapabilitiesSyntaxDocExistsWithTrackedIssues(): void
    {
        $doc = dirname(__DIR__, 2).'/docs/capabilities-syntax.md';
        $this->assertFileExists($doc);
        $body = (string) file_get_contents($doc);
        $this->assertStringContainsString('#58', $body);
        $this->assertStringContainsString('#568', $body);
        $this->assertStringContainsString('#764', $body);
        $this->assertStringContainsString('execute closed', $body);
        $this->assertStringContainsString('#1760', $body);
        $this->assertStringNotContainsString('blocked #568', $body);
        $this->assertStringNotContainsString('execute #764', $body);
        $this->assertStringNotContainsString('native execute #764', $body);
        $this->assertStringContainsString('#199', $body);
        $this->assertStringContainsString('Native user-class link', $body);
        $this->assertStringContainsString('Magic constants', $body);
        $this->assertStringContainsString('| Construct | VM | JIT | AOT | Issue | Notes |', $body);
        $this->assertStringContainsString('## Stdlib array builtins (sort / merge)', $body);
        $this->assertStringContainsString('`ksort()`', $body);
        $this->assertStringContainsString('`array_merge()` on string-key associative arrays', $body);
        $this->assertStringContainsString('`rsort()`', $body);
        $this->assertStringContainsString('`array_rand()`', $body);
        $this->assertStringContainsString('#2321', $body);
        $this->assertStringContainsString('#2271', $body);
        $this->assertStringContainsString('#2287', $body);
        $this->assertStringContainsString('## Web north-star', $body);
        $this->assertStringContainsString('PATH_INFO / `?route=` fallback', $body);
        $this->assertStringContainsString('`phpc_deploy_path()` + `PHPC_DEPLOY_ROOT`', $body);
        $this->assertStringContainsString('CGI/1.1 driver', $body);
        $this->assertStringContainsString('| CGI/1.1 driver (`bin/cgi.php`) | yes | n/a | n/a |', $body);
        $this->assertStringContainsString('AOT CGI (`cgi-wrapper` + `phpc cgi`)', $body);
        $this->assertStringContainsString('| AOT CGI (`cgi-wrapper` + `phpc cgi`) | n/a | n/a | partial |', $body);
        $this->assertStringContainsString('| PATH_INFO / `?route=` fallback | yes | partial | partial |', $body);
        $this->assertStringContainsString('| Native user-class link (`phpc build --project`) | yes | yes | yes |', $body);
        $this->assertStringContainsString('#665', $body);
        $this->assertStringContainsString('#489', $body);
        $this->assertStringContainsString('#173', $body);
        $this->assertStringContainsString('#195', $body);
        $this->assertStringContainsString('## Throws reference (`examples/007-ThrowsWeb`)', $body);
        $this->assertStringContainsString('`007-ThrowsWeb` reference app', $body);
        $this->assertStringContainsString('THROWS_WEB_SMOKE_GATE', $body);
    }

    public function testCapabilitiesMdLinksToSyntaxMatrix(): void
    {
        $doc = dirname(__DIR__, 2).'/docs/capabilities.md';
        $body = (string) file_get_contents($doc);
        $this->assertStringContainsString('capabilities-syntax.md', $body);
        $this->assertStringContainsString('capability-syntax.php', $body);
    }

    public function testCiInventoryRunsCapabilitySyntaxCheck(): void
    {
        $common = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-common.sh');
        $this->assertStringContainsString('ci_run_capability_syntax_check', $common);
        $this->assertStringContainsString('capability-syntax.php --check', $common);
        $defaults = (string) file_get_contents(dirname(__DIR__, 2).'/script/ci-defaults.env');
        $this->assertStringContainsString('CAPABILITY_SYNTAX_CHECK="${CAPABILITY_SYNTAX_CHECK:-1}"', $defaults);
    }
}
