<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BootstrapM3StrictSyncTest extends TestCase
{
    public function testCheckBootstrapM3StrictSyncPassesOnMasterTree(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/check-bootstrap-m3-strict-sync.php').' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $joined = implode("\n", $out);
        $this->assertSame(0, $code, $joined);
        $this->assertStringContainsString('check-bootstrap-m3-strict-sync: OK', $joined);
    }

    public function testScriptProfileDetectsZendFallbackAndStrictGate(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-m3-strict-sync-lib.php';
        $root = dirname(__DIR__, 2);
        $probe = (string) file_get_contents($root.'/script/bootstrap-selfhost-compile-smoke-probe.sh');
        $profile = bootstrap_m3_compile_smoke_script_profile($probe);
        $this->assertTrue($profile['zend_fallback']);
        $this->assertTrue($profile['strict_env']);
        $this->assertTrue($profile['link_compile_driver_env']);
        $this->assertContains('emit_path=zend partial', $profile['emit_path_tokens']);
    }

    public function testValidateDocFailsWhenZendClaimMissing(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-m3-strict-sync-lib.php';
        $profile = [
            'zend_fallback' => true,
            'native_success' => true,
            'strict_env' => true,
            'link_compile_driver_env' => true,
            'runtime_compile_env' => false,
            'emit_path_tokens' => ['emit_path=zend partial'],
        ];
        $errors = [];
        bootstrap_m3_strict_validate_doc('fake.md', 'bootstrap-selfhost-compile-smoke-probe only', $profile, $errors);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Zend', implode(' ', $errors));
    }
}
