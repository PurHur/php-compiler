<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPUnit\Framework\TestCase;

/**
 * #36382 — multi-trait property slots must use composing-class layout.
 *
 * RequestTrait::$uri must not alias MessageTrait::$protocol (trait-local slot N).
 * php-src: Zend/zend_inheritance.c zend_do_traits_property_binding
 *
 * @group aot
 */
final class Issue36382TraitPropertySlotComposeAotTest extends TestCase
{
    public function testTraitUriPropRoundTripsObjectUnderAot(): void
    {
        if (!getenv('PHP_COMPILER_LLVM_PATH') && !is_dir(__DIR__.'/../../.llvm')) {
            $this->markTestSkipped('LLVM 9 not available');
        }
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_trait_uri_with_message.php';
        $this->assertFileExists($src);
        $bin = sys_get_temp_dir().'/issue_36382_trait_uri_slots_'.getmypid();
        @unlink($bin);
        $cmd = sprintf(
            'php -d memory_limit=512M %s --no-cache -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
        @unlink($bin);
        $this->assertSame(0, $runCode, implode("\n", $runOut));
        $joined = implode("\n", $runOut)."\n";
        $this->assertStringContainsString('uri_type=UriObj36382b', $joined);
        $this->assertStringNotContainsString('FAIL_NOT_OBJECT', $joined);
        $this->assertStringContainsString("method=GET\n", $joined);
        $this->assertStringContainsString("ok\n", $joined);
    }

    public function testTraitPropSlotDumpMatchesZend(): void
    {
        if (!getenv('PHP_COMPILER_LLVM_PATH') && !is_dir(__DIR__.'/../../.llvm')) {
            $this->markTestSkipped('LLVM 9 not available');
        }
        $repo = dirname(__DIR__, 2);
        $src = $repo.'/test/repro/issue_36382_trait_uri_slot_dump.php';
        $this->assertFileExists($src);
        $bin = sys_get_temp_dir().'/issue_36382_trait_slot_dump_'.getmypid();
        @unlink($bin);
        $cmd = sprintf(
            'php -d memory_limit=512M %s --no-cache -o %s %s 2>&1',
            escapeshellarg($repo.'/bin/compile.php'),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($cmd, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $zend = [];
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($src).' 2>&1', $zend, $zec);
        $this->assertSame(0, $zec, implode("\n", $zend));
        $got = [];
        exec(escapeshellarg($bin).' 2>&1', $got, $aec);
        @unlink($bin);
        $this->assertSame(0, $aec, implode("\n", $got));
        $this->assertSame($zend, $got);
    }
}
