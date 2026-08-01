<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * Explicit `: mixed` still requires a returned value (#26485, Zend/zend_execute.c).
 */
final class MixedMissingReturnTest extends TestCase
{
    public function testMixedFallOffRequiresVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
function g(): mixed {}
PHP,
            'mixed_missing_return.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsTypedNonVoidReturnOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testUntypedMixedCfgDoesNotRequireVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
function g() {}
PHP,
            'untyped_return.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsTypedNonVoidReturnOpcodes($block));
    }

    public function testVmReproMatchesZendTypeError(): void
    {
        $bin = realpath(__DIR__.'/../../bin/vm.php');
        $repro = realpath(__DIR__.'/../repro/maintainer_gap_mixed_missing_return.php');
        $this->assertNotFalse($bin);
        $this->assertNotFalse($repro);
        $cmd = [PHP_BINARY, $bin, $repro];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__, 2)
        );
        $this->assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $this->assertSame(0, $code, $out);
        $this->assertStringContainsString(
            'missing_mixed=TypeError:missing_mixed(): Return value must be of type mixed, none returned',
            $out
        );
        $this->assertStringContainsString('ok_mixed_null=NULL', $out);
        $this->assertStringContainsString('untyped_ok=NULL', $out);
        $this->assertStringContainsString('void_ok=NULL', $out);
    }
}
