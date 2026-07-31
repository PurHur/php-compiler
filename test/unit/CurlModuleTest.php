<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * curl extension module registration (issue #6999, #16659, #3325).
 *
 * @group curl_module_skeleton
 */
final class CurlModuleTest extends TestCase
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

    public function test_curl_module_libcurl_easy_functions_constants_and_classes(): void
    {
        if (!\PHPCompiler\ext\curl\VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec', 'curl_getinfo', 'curl_error', 'curl_errno', 'curl_close', 'curl_reset', 'curl_pause', 'curl_copy_handle'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_share_init', 'curl_share_setopt', 'curl_share_close', 'curl_share_errno', 'curl_share_strerror'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach ([
            'curl_multi_init',
            'curl_multi_add_handle',
            'curl_multi_exec',
            'curl_multi_select',
            'curl_multi_getcontent',
            'curl_multi_remove_handle',
            'curl_multi_close',
            'curl_multi_info_read',
            'curl_multi_setopt',
            'curl_multi_errno',
        ] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        // curl_multi_get_handles is PHP 8.5-only (#20520); withheld on default 8.4 profile.
        self::assertSame(
            \PHPCompiler\CompilerVersion::advertisesCurlMultiGetHandles(),
            VmReflection::functionExists($ctx, 'curl_multi_get_handles'),
            'curl_multi_get_handles advertisement must match CompilerVersion gate'
        );
        foreach (['curl_version', 'curl_strerror', 'curl_multi_strerror', 'curl_upkeep', 'curl_file_create'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_escape', 'curl_unescape'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        self::assertTrue(VmReflection::classExists($ctx, 'CURLFile'));
        self::assertTrue(VmReflection::classExists($ctx, 'CURLStringFile'));
        self::assertTrue(VmReflection::classExists($ctx, 'CurlHandle'));

        $code = <<<'PHP'
<?php
echo (int) function_exists('curl_init');
echo (int) function_exists('curl_setopt');
echo (int) function_exists('curl_exec');
echo (int) function_exists('curl_close');
echo (int) function_exists('curl_reset');
echo (int) function_exists('curl_pause');
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
echo (int) defined('CURLPAUSE_ALL');
echo (int) defined('CURLOPT_TIMEOUT');
echo CURLOPT_TIMEOUT;
echo (int) defined('CURLOPT_FOLLOWLOCATION');
echo CURLOPT_FOLLOWLOCATION;
PHP;
        $block = $runtime->parseAndCompile($code, 'curl_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11111111111111110002111113152', ob_get_clean());
    }
}
