<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * xml_parser_set_option(..., null) — E_WARNING string|int|bool, still returns true (#30652).
 *
 * php-src: ext/xml/xml.c PHP_FUNCTION(xml_parser_set_option) / xml.stub.php string|int|bool $value.
 */
final class XmlParserSetOptionNull30652Test extends TestCase
{
    public function testNullValueWarnsAndReturnsTrue(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
$p = xml_parser_create();
try {
    var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, null));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
echo "\n";
var_export(xml_parser_get_option($p, XML_OPTION_CASE_FOLDING));
echo "\n";
xml_parser_free($p);
PHP);
        $this->assertStringContainsString(
            'ERR[2]: xml_parser_set_option(): Argument #3 ($value) must be of type string|int|bool, null given',
            $out
        );
        $this->assertStringContainsString("true\n", $out);
        $this->assertStringContainsString("0\n", $out);
        $this->assertStringNotContainsString('TypeError', $out);
    }

    public function testFloatValueAlsoWarns(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
$p = xml_parser_create();
var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 1.5));
echo "\n";
xml_parser_free($p);
PHP);
        $this->assertStringContainsString(
            'ERR[2]: xml_parser_set_option(): Argument #3 ($value) must be of type string|int|bool, float given',
            $out
        );
        $this->assertStringContainsString("true\n", $out);
    }

    public function testBoolIntStringPathsStaySilent(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
$p = xml_parser_create();
var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, true));
echo "\n";
var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, 0));
echo "\n";
var_export(xml_parser_set_option($p, XML_OPTION_SKIP_WHITE, false));
echo "\n";
xml_parser_free($p);
PHP);
        $this->assertSame("true\ntrue\ntrue\n", $out);
    }

    public function testReferenceProfileNullIsSilent(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
$p = xml_parser_create();
var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, null));
echo "\n";
xml_parser_free($p);
PHP, '8.2');
        $this->assertSame("true\n", $out);
    }

    public function testStrictTypesNullStillWarnsNotTypeError(): void
    {
        $out = $this->runVm(<<<'PHP'
<?php
declare(strict_types=1);
error_reporting(E_ALL);
set_error_handler(static function (int $errno, string $errstr): bool {
    echo "ERR[$errno]: $errstr\n";
    return true;
});
$p = xml_parser_create();
try {
    var_export(xml_parser_set_option($p, XML_OPTION_CASE_FOLDING, null));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo "\n";
xml_parser_free($p);
PHP);
        $this->assertStringContainsString(
            'ERR[2]: xml_parser_set_option(): Argument #3 ($value) must be of type string|int|bool, null given',
            $out
        );
        $this->assertStringContainsString('true', $out);
        $this->assertStringNotContainsString('TypeError', $out);
    }

    private function runVm(string $script, string $profile = '8.4'): string
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/vm.php');
        $this->assertNotFalse($bin);
        $tmp = tempnam(sys_get_temp_dir(), 'phpc_xpso_');
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
            $env['PHP_COMPILER_PROFILE'] = $profile;
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
