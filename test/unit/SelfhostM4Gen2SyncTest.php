<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SelfhostM4Gen2SyncTest extends TestCase
{
    public function testCheckSelfhostM4Gen2SyncPassesOnMasterTree(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/check-selfhost-m4-gen2-sync.php').' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $joined = implode("\n", $out);
        $this->assertSame(0, $code, $joined);
        $this->assertStringContainsString('check-selfhost-m4-gen2-sync: OK', $joined);
    }

    public function testScriptProfileDetectsZendFallbackAndStrictGate(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-m4-gen2-sync-lib.php';
        $root = dirname(__DIR__, 2);
        $gen1 = (string) file_get_contents($root.'/script/bootstrap-loop-gen1-link.sh');
        $probe = (string) file_get_contents($root.'/script/bootstrap-loop-probe.sh');
        $profile = bootstrap_m4_gen2_script_profile($gen1, $probe);
        $this->assertTrue($profile['zend_fallback']);
        $this->assertTrue($profile['gen2_strict_env']);
        $this->assertTrue($profile['link_compile_driver_env']);
        $this->assertContains('emit_path=zend partial', $profile['emit_path_tokens']);
    }

    public function testValidateDocFailsWhenZendClaimMissing(): void
    {
        require_once dirname(__DIR__, 2).'/script/bootstrap-m4-gen2-sync-lib.php';
        $profile = [
            'zend_fallback' => true,
            'native_success' => true,
            'gen2_strict_env' => true,
            'link_compile_driver_env' => true,
            'runtime_compile_env' => false,
            'emit_path_tokens' => ['emit_path=zend partial'],
        ];
        $errors = [];
        bootstrap_m4_gen2_validate_doc('fake.md', 'M4 bootstrap-loop-gen1-link only', $profile, $errors);
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Zend', implode(' ', $errors));
    }
}
