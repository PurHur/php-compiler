<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #13588 */
final class CurlEscapeBuiltinTest extends TestCase
{
    public function testCurlEscapeNotAdvertisedWithoutCurl(): void
    {
        if (CurlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('curl extension advertised — phantom guard N/A');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('curl_escape'), "\n";
echo (int) function_exists('curl_unescape'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_escape_phantom.php'));
        $this->assertSame("0\n0\n", ob_get_clean());
    }
}
