<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT getservbyname('http','tcp') must be false when /etc/services is absent (#27198).
 *
 * @group llvm
 * @group aot
 */
final class Issue27198GetservbynameAotTest extends TestCase
{
    public function testAotMatchesZendWhenServicesDbAbsent(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        if (is_readable('/etc/services')) {
            $this->markTestSkipped('/etc/services present — issue repro needs absent services DB');
        }
        $root = dirname(__DIR__, 2);
        $src = $root.'/test/repro/issue_27198_getservbyname_aot.php';
        $bin = sys_get_temp_dir().'/phpc_27198_'.getmypid().'.bin';
        $compile = sprintf(
            'cd %s && ./phpc build -o %s %s 2>&1',
            escapeshellarg($root),
            escapeshellarg($bin),
            escapeshellarg($src)
        );
        exec($compile, $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
        $this->assertFileExists($bin);
        exec(escapeshellarg($bin).' 2>&1', $runOut, $runCode);
        @unlink($bin);
        $this->assertSame(0, $runCode, implode("\n", $runOut));
        $this->assertSame("false\n", implode("\n", $runOut)."\n");
    }

    public function testThinAotHelperDoesNotInventHttpPort(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/ext/standard/NetworkServicesNameLookupThinAot.php'
        );
        $this->assertStringContainsString('#27198', $src);
        $this->assertStringContainsString('return -1', $src);
        // Must not hardcode http→80 unconditionally (pre-#27198 NestedJIT table).
        $this->assertDoesNotMatchRegularExpression(
            "/if\\s*\\(\\s*'http'\\s*===\\s*\\\$svc\\s*\\)\\s*\\{\\s*return\\s*80\\s*;/",
            $src
        );
    }
}
