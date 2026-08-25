<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DateTimeImmutable mutator returns format/getTimestamp match Zend (#34651).
 *
 * @see php-src ext/date/php_date.c — zim_DateTimeImmutable_modify / _add / _sub / _setTime
 *
 * @group llvm
 * @group aot
 */
final class Issue34651DateTimeImmutableMutatorFormatAotTest extends TestCase
{
    public function testRestoreDoesNotUseUniqueLocalFallback(): void
    {
        $src = (string) file_get_contents(
            dirname(__DIR__, 2).'/lib/JIT/Call/DateTimeFormat.php'
        );
        $this->assertStringContainsString('#34651', $src);
        $this->assertStringContainsString('lastDateTimeUnserializeLocalName', $src);
        $this->assertStringNotContainsString(
            '1 === \\count($context->dateTimeLocalInstants)',
            $src
        );

        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('restoreUnserializeDateTimeInstantOnto', $jit);
        $this->assertStringContainsString('#34651', $jit);

        $civil = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitDate.php');
        $this->assertStringContainsString("'H:i'", $civil);
        $this->assertStringContainsString('#34651', $civil);
    }

    public function testAotMutatorsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $src = __DIR__.'/../repro/issue_34651_datetimeimmutable_mutator_format_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    public function testAotNamedZoneUnserializeStillMatchesZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        // Peer #34614 must stay green after narrowing the restore fallback.
        $src = __DIR__.'/../repro/issue_34614_datetime_named_zone_unserialize_aot.php';
        $zend = $this->runPhp($src);
        $aot = $this->runAot($src);
        $this->assertSame($zend, $aot);
    }

    private function runPhp(string $src): string
    {
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($src);
        exec($cmd.' 2>&1', $out, $rc);
        $this->assertSame(0, $rc, implode("\n", $out));

        return implode("\n", $out)."\n";
    }

    private function runAot(string $src): string
    {
        $root = dirname(__DIR__, 2);
        $bin = sys_get_temp_dir().'/dti_34651_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            $out = [];
            for ($i = 0; $i < 5; ++$i) {
                $runOut = [];
                $runRc = 0;
                exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
                $this->assertSame(0, $runRc, 'run '.($i + 1).': '.implode("\n", $runOut));
                $chunk = implode("\n", $runOut)."\n";
                if (0 === $i) {
                    $out = $runOut;
                } else {
                    $this->assertSame(implode("\n", $out)."\n", $chunk, 'run '.($i + 1));
                }
            }

            return implode("\n", $out)."\n";
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
