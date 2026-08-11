<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * fscanf(null) $format under strict_types — TypeError like Zend (#30236).
 */
final class FscanfNullFormatStrict30236Test extends TestCase
{
    public function testVmTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/vm.php'), 'issue_30236_fscanf_null_strict.php');
        $this->assertSame(
            "TypeError: fscanf(): Argument #2 (\$format) must be of type string, null given\n",
            $out
        );
    }

    public function testVmSoftNullWithoutStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/vm.php'), 'issue_30236_fscanf_null_nonstrict.php');
        $this->assertSame("false NO_THROW\n", $out);
    }

    public function testJitTypeErrorUnderStrictTypes(): void
    {
        $out = $this->runPhpScript(realpath(dirname(__DIR__, 2).'/bin/jit.php'), 'issue_30236_fscanf_null_strict.php');
        $this->assertStringContainsString(
            'fscanf(): Argument #2 ($format) must be of type string, null given',
            $out
        );
        $this->assertStringNotContainsString('NO_THROW', $out);
    }

    private function runPhpScript(string $bin, string $reproName): string
    {
        $root = dirname(__DIR__, 2);
        $repro = realpath($root.'/test/repro/'.$reproName);
        $this->assertNotFalse($repro);
        $cmd = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', $bin, $repro];
        $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $env = [];
        foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
            if (is_string($value) || is_int($value) || is_float($value)) {
                $env[(string) $key] = (string) $value;
            }
        }
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, (string) $stdout.(string) $stderr);

        return (string) $stdout;
    }
}
