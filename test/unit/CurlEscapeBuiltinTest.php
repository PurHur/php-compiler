<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @covers issue #13588, #16756 */
final class CurlEscapeBuiltinTest extends TestCase
{
    public function testCurlEscapeNotAdvertisedWithoutCurlExtension(): void
    {
        if (!CurlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('curl phase-2 builtins not registered');
        }
        $runtime = new \PHPCompiler\Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('curl_escape'), "\n";
echo (int) function_exists('curl_unescape'), "\n";
var_export(curl_escape('a b'));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_escape_exists.php'));
        $this->assertSame("0\n0\n'a%20b'\n", ob_get_clean());
    }
}
