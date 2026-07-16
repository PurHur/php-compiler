<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPCompiler\ext\curl\VmCurlCore;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** @group curl_extension_policy */
final class CurlExtensionPolicyTest extends TestCase
{
    public function testCurlPhase2BuiltinsAdvertised(): void
    {
        self::assertTrue(CurlExtensionPolicy::advertisesBuiltins());
        self::assertFalse(CurlExtensionPolicy::advertisesExtension());
        self::assertFalse(CurlExtensionPolicy::advertisesHandleClasses());
        self::assertFalse(CurlExtensionPolicy::advertisesFileClasses());
        self::assertFalse(CurlExtensionPolicy::advertisesShareHandles());
        self::assertFalse(CurlExtensionPolicy::advertisesEasyHandleStubs());
        self::assertFalse(CurlExtensionPolicy::advertisesIntrospectionFunctions());
    }

    public function testCurlShareHandleClassWithheldWithoutExtension(): void
    {
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
        // Share must not outlive curl_init / CurlHandle (#19728, re-#12117).
        self::assertSame("false\nfalse\nfalse\nfalse\nfalse", ob_get_clean());
    }

    public function testCurlHandleClassesWithheldUntilExtensionAdvertised(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(class_exists('CurlMultiHandle', false));
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'curl_multi_handle.php'));
        self::assertSame('false', ob_get_clean());
    }

    public function testCurlVersionCore(): void
    {
        self::assertSame('No error', VmCurlCore::easyStrerror(0));
        self::assertSame('No error', VmCurlCore::multiStrerror(0));
        self::assertNull(VmCurlCore::easyStrerror(9999));
        $info = VmCurlCore::versionInfo();
        self::assertSame(VmCurlCore::LIBCURL_VERSION, $info['version']);
    }

    public function testCurlFileClassesWithheldWithoutExtension(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        self::assertFalse(VmReflection::classExists($ctx, 'CURLFile'));
        self::assertFalse(VmReflection::classExists($ctx, 'CURLStringFile'));
        foreach (['curl_version', 'curl_strerror', 'curl_multi_strerror', 'curl_upkeep', 'curl_file_create'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_escape', 'curl_unescape'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
    }

    public function testCurlIntrospectionFunctionsRegistered(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        foreach (['curl_version', 'curl_strerror', 'curl_multi_strerror', 'curl_upkeep', 'curl_file_create'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        foreach (['curl_escape', 'curl_unescape'] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }
        self::assertFalse(VmReflection::classExists($ctx, 'CURLStringFile'));
        self::assertFalse(VmReflection::classExists($ctx, 'CURLFile'));
    }
}
