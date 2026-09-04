<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * PHP 8.3 baseline ADR + syntax probe cache (#36384).
 */
final class CapabilitySyntaxBaseline36384Test extends TestCase
{
    public function testPhp83BaselineAdrExists(): void
    {
        $adr = dirname(__DIR__, 2).'/docs/adr/36384-php-83-baseline.md';
        $this->assertFileExists($adr);
        $body = (string) file_get_contents($adr);
        $this->assertStringContainsString('PHP 8.3', $body);
        $this->assertStringContainsString('Accepted', $body);
        $this->assertStringContainsString('#36384', $body);
    }

    public function testCapabilitiesSyntaxDocumentsBaselineAndOverrideRow(): void
    {
        $doc = dirname(__DIR__, 2).'/docs/capabilities-syntax.md';
        $this->assertFileExists($doc);
        $body = (string) file_get_contents($doc);
        $this->assertStringContainsString('Language baseline (v2.0)', $body);
        $this->assertStringContainsString('adr/36384-php-83-baseline.md', $body);
        $this->assertStringContainsString('`#[\\Override]`', $body);
        $this->assertStringContainsString('capability-syntax-probe-cache.json', $body);
        $this->assertStringContainsString('--refresh-probes', $body);
    }

    public function testProbeCacheSchemaWhenPresent(): void
    {
        $cache = dirname(__DIR__, 2).'/script/capability-syntax-probe-cache.json';
        $this->assertFileExists($cache);
        $decoded = json_decode((string) file_get_contents($cache), true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('lowering_fingerprint', $decoded);
        $this->assertArrayHasKey('definitions_hash', $decoded);
        $this->assertArrayHasKey('rows', $decoded);
        $this->assertIsArray($decoded['rows']);
        $this->assertArrayHasKey('override_attribute', $decoded['rows']);
        $row = $decoded['rows']['override_attribute'];
        $this->assertArrayHasKey('vm', $row);
        $this->assertArrayHasKey('aot', $row);
    }

    public function testGettingStartedMentionsBaseline(): void
    {
        $doc = dirname(__DIR__, 2).'/docs/GETTING-STARTED.md';
        $body = (string) file_get_contents($doc);
        $this->assertStringContainsString('PHP 8.3 semantics', $body);
        $this->assertStringContainsString('36384-php-83-baseline.md', $body);
    }
}
