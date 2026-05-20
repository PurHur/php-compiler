<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Bootstrap self-host profile (issue #212 Phase B).
 */
final class BootstrapProfileTest extends TestCase
{
    public function testProfileScriptBuildsValidJson(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-profile.php').' 2>/dev/null';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $json = (string) file_get_contents($root.'/docs/bootstrap-profile.json');
        $profile = json_decode($json, true);
        $this->assertIsArray($profile);
        $this->assertSame('B', $profile['phase']);
        $this->assertContains('examples/000-HelloWorld/example.php', $profile['aot_lint_targets']);
        $this->assertContains('test/bootstrap-aot/echo_hello.php', $profile['aot_lint_targets']);
        $this->assertContains('lib/AOT/Linker.php', $profile['excluded_files']);
    }

    public function testProfileDocIsFresh(): void
    {
        $root = dirname(__DIR__, 2);
        $doc = $root.'/docs/bootstrap-profile.json';
        $this->assertFileExists($doc);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/bootstrap-profile.php').' --check 2>&1';
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
