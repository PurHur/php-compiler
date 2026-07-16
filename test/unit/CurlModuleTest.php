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

        foreach (['curl_init', 'curl_setopt', 'curl_close'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_share_init', 'curl_share_setopt', 'curl_share_close'] as $fn) {
            // Share APIs stay with curl_init until extension_loaded('curl') (#19728).
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_version', 'curl_strerror', 'curl_multi_strerror', 'curl_upkeep', 'curl_file_create'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_escape', 'curl_unescape'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        self::assertFalse(VmReflection::classExists($ctx, 'CURLFile'));
        self::assertFalse(VmReflection::classExists($ctx, 'CURLStringFile'));

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
echo (int) function_exists('curl_file_create');
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
        self::assertSame('00000000000011000210', ob_get_clean());
    }
}
