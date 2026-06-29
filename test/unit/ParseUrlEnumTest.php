<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7260 */
final class ParseUrlEnumTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    public function testParseUrlBuiltinEnumExists(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('ParseUrl', false));
echo "\n";
var_export(ParseUrl::Host->name);
echo "\n";
var_export(ParseUrl::Host->value);
echo "\n";
var_export(ParseUrl::Path->value);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'parseurl_enum.php'));
        $this->assertSame("true\n'Host'\n1\n5", ob_get_clean());
    }

    public function testParseUrlAcceptsParseUrlEnumAndNamedComponent(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$url = 'http://user:pass@example.com:8080/path?q=1#frag';
echo parse_url($url, ParseUrl::Host), "\n";
echo parse_url($url, component: ParseUrl::Path), "\n";
echo parse_url($url, ParseUrl::Port), "\n";
echo parse_url($url, PHP_URL_USER), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'parseurl_enum_use.php'));
        $this->assertSame("example.com\n/path\n8080\nuser\n", ob_get_clean());
    }

    public function testParseUrlEnumComponentAotLint(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/parse_url_enum.php';
        $cmd = [PHP_BINARY, $bin, '-l', $target];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open($cmd, $descriptorSpec, $pipes, $root);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(
            0,
            $exit,
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for parse_url ParseUrl enum probe (#7260)'
        );
    }
}
