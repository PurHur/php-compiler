<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @covers issue #13588, #16756, #20493 */
final class CurlEscapeBuiltinTest extends TestCase
{
    /** @var string|false|null */
    private $prevEnable = null;

    protected function setUp(): void
    {
        $this->prevEnable = getenv('PHP_COMPILER_ENABLE_CURL');
        putenv('PHP_COMPILER_ENABLE_CURL=1');
    }

    protected function tearDown(): void
    {
        if (false === $this->prevEnable || null === $this->prevEnable) {
            putenv('PHP_COMPILER_ENABLE_CURL');
        } else {
            putenv('PHP_COMPILER_ENABLE_CURL='.$this->prevEnable);
        }
    }

    public function testCurlEscapeRequiresCurlHandleWhenExtensionLoaded(): void
    {
        if (!CurlExtensionPolicy::advertisesExtension()) {
            $this->markTestSkipped('curl extension not loaded');
        }
        $runtime = new \PHPCompiler\Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('curl_escape'), "\n";
echo (int) function_exists('curl_unescape'), "\n";
$ch = curl_init();
var_export(curl_escape($ch, 'a b'));
echo "\n";
try {
    curl_escape('a b');
    echo "1arg_ok\n";
} catch (ArgumentCountError $e) {
    echo "1arg_err\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_escape_handle.php'));
        $this->assertSame("1\n1\n'a%20b'\n1arg_err\n", ob_get_clean());
    }
}
