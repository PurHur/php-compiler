<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPUnit\Framework\TestCase;

/** @group curl_extension_policy */
final class CurlExtensionPolicyTest extends TestCase
{
    public function testCurlHandleClassesWithheldUntilCurlLoaded(): void
    {
        self::assertFalse(CurlExtensionPolicy::advertisesHandleClasses());
        self::assertFalse(CurlExtensionPolicy::advertisesBuiltins());
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('CurlHandle', false));
echo "\n";
var_export(class_exists('CurlMultiHandle', false));
echo "\n";
var_export(class_exists('CurlShareHandle', false));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_handle_phantom.php'));
        self::assertSame("false\nfalse\nfalse", ob_get_clean());
    }
}
