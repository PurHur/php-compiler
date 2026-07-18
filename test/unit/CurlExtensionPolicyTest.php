<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPCompiler\ext\curl\VmCurlCore;
use PHPCompiler\ext\curl\VmCurlNative;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** @group curl_extension_policy */
final class CurlExtensionPolicyTest extends TestCase
{
    public function testCurlPhase2BuiltinsAdvertised(): void
    {
        self::assertTrue(CurlExtensionPolicy::advertisesBuiltins());
        $native = VmCurlNative::available();
        self::assertSame($native, CurlExtensionPolicy::advertisesExtension());
        self::assertSame($native, CurlExtensionPolicy::advertisesHandleClasses());
        self::assertSame($native, CurlExtensionPolicy::advertisesFileClasses());
        self::assertSame($native, CurlExtensionPolicy::advertisesShareHandles());
        self::assertSame($native, CurlExtensionPolicy::advertisesEasyHandleStubs());
        self::assertSame($native, CurlExtensionPolicy::advertisesMultiHandles());
        self::assertSame($native, CurlExtensionPolicy::advertisesIntrospectionFunctions());
    }

    public function testCurlShareHandleClassTracksExtension(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('CurlHandle', false));
echo "\n";
var_export(class_exists('CurlMultiHandle', false));
echo "\n";
var_export(class_exists('CurlShareHandle', false));
echo "\n";
var_export(class_exists('CURLStringFile', false));
echo "\n";
var_export(class_exists('CURLFile', false));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_handle_classes.php'));
        self::assertSame("true\ntrue\ntrue\ntrue\ntrue", ob_get_clean());
    }

    public function testCurlMultiHandleClassWithExtension(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('CurlMultiHandle', false));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_multi_handle.php'));
        self::assertSame('true', ob_get_clean());
    }

    public function testCurlVersionCore(): void
    {
        self::assertSame('No error', VmCurlCore::easyStrerror(0));
        self::assertSame('No error', VmCurlCore::multiStrerror(0));
        self::assertNull(VmCurlCore::easyStrerror(9999));
        $info = VmCurlCore::versionInfo();
        self::assertSame(VmCurlCore::LIBCURL_VERSION, $info['version']);
    }

    public function testCurlFileClassesTrackExtension(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        self::assertTrue(VmReflection::classExists($ctx, 'CURLFile'));
        self::assertTrue(VmReflection::classExists($ctx, 'CURLStringFile'));
        foreach (['curl_version', 'curl_strerror', 'curl_multi_strerror', 'curl_upkeep', 'curl_file_create'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_escape', 'curl_unescape'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }
    }

    public function testCurlMultiFunctionsRegistered(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
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
    }
}
