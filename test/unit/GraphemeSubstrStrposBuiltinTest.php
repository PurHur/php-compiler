<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3352 */
final class GraphemeSubstrStrposBuiltinTest extends TestCase
{
    public function testGraphemeSubstrStrposNotAdvertisedWithoutIntl(): void
    {
        if (IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension advertised — phantom guard N/A');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('grapheme_substr'), "\n";
echo (int) function_exists('grapheme_strpos'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'grapheme_substr_strpos_phantom.php'));
        $this->assertSame("0\n0\n", ob_get_clean());
    }

    public function testGraphemeSubstrStrposBuiltinExists(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension not advertised (#11768)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('grapheme_substr'), "\n";
echo (int) function_exists('grapheme_strpos'), "\n";
$s = "a\xCC\x81b";
echo grapheme_strlen($s), "\n";
echo grapheme_substr($s, 0, 1), "\n";
echo grapheme_substr($s, 1), "\n";
var_export(grapheme_strpos($s, 'b'));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'grapheme_substr_strpos.php'));
        $this->assertSame("1\n1\n2\na\xCC\x81\nb\n1\n", ob_get_clean());
    }

    public function testGraphemeSubstrEnumTypeError(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension not advertised (#11768)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum Es: string { case A = 'a'; }
try {
    grapheme_substr(Es::A, 0);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'grapheme_substr_enum.php'));
        $this->assertSame(
            "grapheme_substr(): Argument #1 (\$string) must be of type string, Es given\n",
            ob_get_clean()
        );
    }

    public function testGraphemeSubstrStrposAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/grapheme_substr_strpos_literals.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for grapheme_substr/strpos probe (#3352)'
        );
    }
}
