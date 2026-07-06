<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * curl extension module registration (issue #6999, #16659).
 *
 * @group curl_module_skeleton
 */
final class CurlModuleTest extends TestCase
{
    public function test_curl_module_phase2_functions_constants_and_classes(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['curl_init', 'curl_setopt', 'curl_exec', 'curl_close'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_version', 'curl_strerror', 'curl_multi_strerror', 'curl_upkeep'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_escape', 'curl_unescape'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        self::assertTrue(VmReflection::classExists($ctx, 'CURLFile'));
        self::assertTrue(VmReflection::classExists($ctx, 'CURLStringFile'));

        $code = <<<'PHP'
<?php
echo (int) function_exists('curl_init');
echo (int) function_exists('curl_setopt');
echo (int) function_exists('curl_exec');
echo (int) function_exists('curl_close');
echo (int) function_exists('curl_version');
echo (int) function_exists('curl_strerror');
echo (int) function_exists('curl_escape');
echo (int) function_exists('curl_unescape');
echo (int) class_exists('CURLFile', false);
echo (int) class_exists('CURLStringFile', false);
echo (int) extension_loaded('curl');
echo (int) defined('CURLOPT_URL');
echo CURLOPT_URL;
echo (int) defined('CURLE_OK');
echo (int) defined('CURLINFO_HTTP_CODE');
PHP;
        $block = $runtime->parseAndCompile($code, 'curl_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0000110011011000210', ob_get_clean());
    }

    public function test_curl_init_stub_class_throws_logic_exception(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\curl\curl_init();
        $frame = $fn->getFrame($runtime->vmContext);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('curl_init() is not implemented in this compiler build (issue #3325)');
        $fn->execute($frame);
    }
}
