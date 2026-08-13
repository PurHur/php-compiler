<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * xml_error_string(null) — soft-null deprecate+coerce; param $error_code (#30651).
 *
 * php-src: ext/xml/xml.c PHP_FUNCTION(xml_error_string) / xml.stub.php int $error_code.
 */
final class XmlErrorStringNull30651Test extends TestCase
{
    public function testSoftNullDeprecatesAndReturnsNoError(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
var_export(xml_error_string(null));
echo "\n";
PHP);
        $this->assertStringContainsString(
            'ERR[8192]: xml_error_string(): Passing null to parameter #1 ($error_code) of type int is deprecated',
            $out
        );
        $this->assertStringContainsString("'No error'", $out);
        $this->assertStringNotContainsString('TypeError', $out);
    }

    public function testArrayTypeErrorUsesErrorCodeParamName(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
try {
    xml_error_string([]);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP);
        $this->assertSame(
            "TypeError: xml_error_string(): Argument #1 (\$error_code) must be of type int, array given\n",
            $out
        );
    }

    public function testReflectionParamNameIsErrorCode(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
$rf = new ReflectionFunction('xml_error_string');
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), "\n";
}
PHP);
        $this->assertSame("error_code\n", $out);
    }

    public function testStrictTypesNullIsTypeError(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
try {
    xml_error_string(null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP);
        $this->assertStringContainsString(
            'TypeError: xml_error_string(): Argument #1 ($error_code) must be of type int, null given',
            $out
        );
        $this->assertStringNotContainsString("'No error'", $out);
    }

    private function runVm(string $script): string
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_xes_');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, $script);
        try {
            $cmd = [PHP_BINARY, '-d', 'error_reporting=E_ALL', '-d', 'display_errors=1', $bin, $tmp];
            $descriptorSpec = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $env = [];
            foreach (array_merge($_ENV, $_SERVER) as $key => $value) {
                if (is_string($value) || is_int($value) || is_float($value)) {
                    $env[(string) $key] = (string) $value;
                }
            }
            $env['PHP_COMPILER_PROFILE'] = '8.4';
            $proc = proc_open($cmd, $descriptorSpec, $pipes, $root, $env);
            $this->assertIsResource($proc);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit = proc_close($proc);
            $this->assertSame(0, $exit, (string) $stdout.(string) $stderr);

            return (string) $stdout.(string) $stderr;
        } finally {
            @unlink($tmp);
        }
    }
}
