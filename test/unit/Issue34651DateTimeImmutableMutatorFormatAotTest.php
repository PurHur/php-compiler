<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * AOT: DateTimeImmutable mutator returns format as mutated instant, not construct stamp (#34651).
 *
 * @see php-src ext/date/php_date.c — zim_DateTimeImmutable_modify / _add / _sub / _setTime
 *
 * @group llvm
 * @group aot
 */
final class Issue34651DateTimeImmutableMutatorFormatAotTest extends TestCase
{
    public function testAotImmutableMutatorsMatchZend(): void
    {
        if (!LlvmToolchain::hasLibrary(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $this->assertAotMatchesZend(
            __DIR__.'/../repro/issue_34651_datetime_immutable_mutator_format_aot.php'
        );
    }

    public function testRestoreFallbackScopedToUnserializeTarget(): void
    {
        $fmt = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT/Call/DateTimeFormat.php');
        $this->assertStringContainsString('#34651', $fmt);
        $this->assertStringNotContainsString('1 === \\count($context->dateTimeLocalInstants)', $fmt);
        $jit = (string) file_get_contents(dirname(__DIR__, 2).'/lib/JIT.php');
        $this->assertStringContainsString('lastDateTimeUnserializeLocalName', $jit);
        $date = (string) file_get_contents(dirname(__DIR__, 2).'/ext/standard/JitDate.php');
        $this->assertStringContainsString("'H:i'", $date);
    }

    private function assertAotMatchesZend(string $src): void
    {
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
        $bin = sys_get_temp_dir().'/immut_34651_'.getmypid().'_'.md5($src);
        $cmd = escapeshellarg(PHP_BINARY).' '
            .escapeshellarg($root.'/bin/compile.php')
            .' -o '.escapeshellarg($bin).' '.escapeshellarg($src);
        $cwd = getcwd();
        chdir($root);
        try {
            exec($cmd.' 2>&1', $compOut, $compRc);
            $this->assertSame(0, $compRc, implode("\n", $compOut));
            $this->assertFileExists($bin);
            exec(escapeshellarg($bin).' 2>&1', $runOut, $runRc);
            $this->assertSame(0, $runRc, implode("\n", $runOut));

            return implode("\n", $runOut)."\n";
        } finally {
            chdir($cwd);
            @unlink($bin);
        }
    }
}
