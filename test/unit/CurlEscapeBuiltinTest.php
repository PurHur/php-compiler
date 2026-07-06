<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @covers issue #13588 */
final class CurlEscapeBuiltinTest extends TestCase
{
    public function testCurlEscapeAdvertisedWithCurlExtension(): void
    {
        if (!CurlExtensionPolicy::advertisesBuiltins()) {
            $this->markTestSkipped('curl extension not advertised');
        }
        $runtime = new \PHPCompiler\Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('curl_escape'), "\n";
echo (int) function_exists('curl_unescape'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_escape_exists.php'));
        $this->assertSame("1\n1\n", ob_get_clean());
    }
}
