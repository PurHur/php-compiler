<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\intl\IntlExtensionPolicy;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #7221 */
final class GraphemeStrstrBuiltinTest extends TestCase
{
    public function testGraphemeStrstrNotAdvertisedWithoutIntl(): void
    {
        if (IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension advertised — phantom guard N/A');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('grapheme_strstr'), "\n";
echo (int) function_exists('grapheme_stristr'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'grapheme_strstr_phantom.php'));
        $this->assertSame("0\n0\n", ob_get_clean());
    }

    public function testGraphemeStrstrBuiltinExists(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension not advertised (#11768)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('grapheme_strstr'), "\n";
echo (int) function_exists('grapheme_stristr'), "\n";
$haystack = "a\xCC\x81bc";
echo grapheme_strstr($haystack, 'b'), "\n";
echo grapheme_stristr("Äbc", 'ä'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'grapheme_strstr.php'));
        $this->assertSame("1\n1\nbc\nÄbc\n", ob_get_clean());
    }

    public function testGraphemeStrstrEnumTypeError(): void
    {
        if (!IntlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('intl extension not advertised (#11768)');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum Es: string { case A = 'a'; }
try {
    grapheme_strstr('ab', Es::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'grapheme_strstr_enum.php'));
        $this->assertSame(
            "grapheme_strstr(): Argument #2 (\$needle) must be of type string, Es given\n",
            ob_get_clean()
        );
    }

    public function testGraphemeStrstrAotLint(): void
    {
        $root = dirname(__DIR__, 2);
        $bin = realpath($root.'/bin/compile.php');
        $this->assertNotFalse($bin);
        $target = $root.'/test/fixtures/aot/compile-only/grapheme_strstr_literals.php';
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
            trim($stderr !== false ? $stderr : '')."\n".'compile.php -l failed for grapheme_strstr probe (#7221)'
        );
    }
}
