<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** @covers issue #10510 — undefined bare constant in namespace error message */
final class NamespaceUndefinedConstantTest extends TestCase
{
    private string $repoRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
    }

    public function testReproFatalCitesFqNameOnceNotDoubled(): void
    {
        $script = $this->repoRoot.'/test/repro/maintainer_gap_ns_undefined_const_empty.php';
        [, $stderr] = $this->runVmScript($script, 255);
        $this->assertStringContainsString('Undefined constant "N\\UNDEF_CONST"', $stderr);
        $this->assertStringNotContainsString('N\\UNDEF_CONST\\UNDEF_CONST', $stderr);
        $this->assertStringContainsString('maintainer_gap_ns_undefined_const_empty.php on line 3', $stderr);
        $this->assertStringNotContainsString('on line 561', $stderr);
    }

    public function testUndefinedFunctionFatalIncludesFileAndLine(): void
    {
        $script = $this->repoRoot.'/test/repro/maintainer_gap_fatal_line_bundle_offset.php';
        [, $stderr] = $this->runVmScript($script, 255);
        $this->assertStringContainsString('Call to undefined function undefined()', $stderr);
        $this->assertStringContainsString('maintainer_gap_fatal_line_bundle_offset.php on line 3', $stderr);
    }

    public function testDefinedNamespaceConstantStillResolves(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_ns_const_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, <<<'PHP'
<?php
namespace N;
const MY_CONST = 42;
\var_export(MY_CONST);
PHP
        );
        [$stdout, $stderr] = $this->runVmScript($tmp, 0);
        @unlink($tmp);
        $this->assertSame('', $stderr);
        $this->assertSame('42', $stdout);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function runVmScript(string $scriptPath, int $expectedExit): array
    {
        $proc = proc_open(
            [PHP_BINARY, $this->repoRoot.'/bin/vm.php', $scriptPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->repoRoot
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $this->assertSame($expectedExit, proc_close($proc), trim($stderr."\n".$stdout));

        return [(string) $stdout, (string) $stderr];
    }
}
