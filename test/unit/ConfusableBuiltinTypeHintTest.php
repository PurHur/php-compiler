<?php

declare(strict_types=1);

namespace PHPCompiler\test\unit;

use PHPCompiler\LlvmToolchain;
use PHPUnit\Framework\TestCase;

/**
 * Issue #26639: bare `resource` type Warning (zend_compile.c confusable types).
 */
final class ConfusableBuiltinTypeHintTest extends TestCase
{
    public function testBareResourceParamWarnsLikeZend(): void
    {
        [$stdout, $stderr, $exit] = $this->runVmCapturingStreams(<<<'PHP'
<?php
function f(resource $x) {}
echo "ok\n";
PHP);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("ok\n", $stdout);
        $this->assertStringContainsString(
            '"resource" is not a supported builtin type and will be interpreted as a class name. Write "\\resource" to suppress this warning',
            $stderr
        );
    }

    public function testFullyQualifiedResourceSuppressesWarning(): void
    {
        [$stdout, $stderr, $exit] = $this->runVmCapturingStreams(<<<'PHP'
<?php
function f(\resource $x) {}
echo "ok\n";
PHP);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("ok\n", $stdout);
        $this->assertStringNotContainsString('is not a supported builtin type', $stderr);
    }

    public function testIntegerConfusableDidYouMean(): void
    {
        [$stdout, $stderr, $exit] = $this->runVmCapturingStreams(<<<'PHP'
<?php
function f(integer $x) {}
echo "ok\n";
PHP);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("ok\n", $stdout);
        $this->assertStringContainsString(
            '"integer" will be interpreted as a class name. Did you mean "int"? Write "\\integer" to suppress this warning',
            $stderr
        );
    }

    public function testNamespacedResourceMentionsUse(): void
    {
        [$stdout, $stderr, $exit] = $this->runVmCapturingStreams(<<<'PHP'
<?php
namespace N;
function f(resource $x) {}
echo "ok\n";
PHP);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("ok\n", $stdout);
        $this->assertStringContainsString(
            'Write "\\N\\resource" or import the class with "use" to suppress this warning',
            $stderr
        );
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function runVmCapturingStreams(string $code): array
    {
        $repo = dirname(__DIR__, 2);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_confusable_type_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $code);
        $env = $_ENV;
        LlvmToolchain::applyProcessEnv($env, $repo);
        $proc = proc_open(
            [PHP_BINARY, $repo.'/bin/vm.php', $tmp],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo,
            $env
        );
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        @unlink($tmp);

        return [
            false !== $stdout ? $stdout : '',
            false !== $stderr ? $stderr : '',
            $exit,
        ];
    }
}
