<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\curl\CurlExtensionPolicy;
use PHPCompiler\ext\curl\VmCurlCore;
use PHPCompiler\ext\curl\VmCurlNative;
use PHPCompiler\ext\standard\ModuleRegistry;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/** @group curl_extension_policy */
final class CurlExtensionPolicyTest extends TestCase
{
    /** @var string|false|null */
    private $prevEnable = null;

    protected function setUp(): void
    {
        $this->prevEnable = getenv('PHP_COMPILER_ENABLE_CURL');
    }

    protected function tearDown(): void
    {
        if (false === $this->prevEnable || null === $this->prevEnable) {
            putenv('PHP_COMPILER_ENABLE_CURL');
        } else {
            putenv('PHP_COMPILER_ENABLE_CURL='.$this->prevEnable);
        }
    }

    public function testWithheldOnReferenceWithoutHostCurl(): void
    {
        if (\extension_loaded('curl')) {
            self::markTestSkipped('host ext/curl loaded');
        }
        putenv('PHP_COMPILER_ENABLE_CURL');

        self::assertFalse(CurlExtensionPolicy::advertisesExtension());
        self::assertFalse(CurlExtensionPolicy::advertisesBuiltins());
        self::assertFalse(CurlExtensionPolicy::advertisesHandleClasses());
        self::assertFalse(CurlExtensionPolicy::advertisesFileClasses());
        self::assertFalse(CurlExtensionPolicy::advertisesShareHandles());
        self::assertFalse(CurlExtensionPolicy::advertisesEasyHandleStubs());
        self::assertFalse(CurlExtensionPolicy::advertisesMultiHandles());
        self::assertFalse(CurlExtensionPolicy::advertisesIntrospectionFunctions());

        $runtime = new Runtime();
        self::assertFalse(ModuleRegistry::extensionLoaded('curl'));
        self::assertFalse(VmReflection::functionExists($runtime->vmContext, 'curl_init'));
        self::assertFalse(VmReflection::functionExists($runtime->vmContext, 'curl_version'));
        self::assertFalse(VmReflection::classExists($runtime->vmContext, 'CurlHandle'));
        self::assertFalse(VmReflection::classExists($runtime->vmContext, 'CURLFile'));
    }

    public function testExplicitEnableAdvertises(): void
    {
        if (\extension_loaded('curl')) {
            self::markTestSkipped('host ext/curl loaded');
        }

        putenv('PHP_COMPILER_ENABLE_CURL=1');
        self::assertTrue(CurlExtensionPolicy::advertisesExtension());
        self::assertTrue(CurlExtensionPolicy::advertisesBuiltins());
        self::assertTrue(CurlExtensionPolicy::advertisesEasyHandleStubs());
    }

    public function testCurlShareHandleClassTracksExtension(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }
        putenv('PHP_COMPILER_ENABLE_CURL=1');

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
        putenv('PHP_COMPILER_ENABLE_CURL=1');

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
        self::assertSame('No error', VmCurlCore::multiStrerror(0));
        if (VmCurlNative::available()) {
            // Live libcurl wording (#25813) — not the deleted VmCurlCore::EASY_ERRORS table.
            self::assertSame('No error', VmCurlNative::easyStrerror(0));
            self::assertSame('Unknown error', VmCurlNative::easyStrerror(9999));
            self::assertStringContainsString('bad/illegal format', VmCurlNative::easyStrerror(3));
        }
        $info = VmCurlCore::versionInfo();
        self::assertSame(VmCurlCore::LIBCURL_VERSION, $info['version']);
    }

    public function testCurlFileClassesTrackExtension(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }
        putenv('PHP_COMPILER_ENABLE_CURL=1');

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
        putenv('PHP_COMPILER_ENABLE_CURL=1');

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
        self::assertFalse(
            VmReflection::functionExists($ctx, 'curl_multi_poll'),
            'curl_multi_poll is libcurl-only; php-src has no PHP wrapper (#21826, #21834)'
        );
        self::assertSame(
            \PHPCompiler\CompilerVersion::advertisesCurlMultiGetHandles(),
            VmReflection::functionExists($ctx, 'curl_multi_get_handles'),
            'curl_multi_get_handles advertisement must match CompilerVersion gate (#20520)'
        );
    }

    public function testCurlMultiGetHandlesRegisteredOnProfile85(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        putenv('PHP_COMPILER_ENABLE_CURL=1');
        try {
            $this->assertTrue(\PHPCompiler\CompilerVersion::advertisesCurlMultiGetHandles());
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            self::assertTrue(VmReflection::functionExists($ctx, 'curl_multi_get_handles'));
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testCurlShareInitPersistentRegisteredOnProfile85(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        putenv('PHP_COMPILER_ENABLE_CURL=1');
        try {
            $this->assertTrue(\PHPCompiler\CompilerVersion::advertisesCurlShareInitPersistent());
            $this->assertTrue(CurlExtensionPolicy::advertisesSharePersistentHandles());
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;
            self::assertTrue(VmReflection::functionExists($ctx, 'curl_share_init_persistent'));
            self::assertTrue(VmReflection::classExists($ctx, 'CurlSharePersistentHandle'));
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testPhp84CurlOptionConstantsProfileGate(): void
    {
        if (!VmCurlNative::available()) {
            $this->markTestSkipped('libcurl FFI unavailable');
        }

        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_ENABLE_CURL=1');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertFalse(\PHPCompiler\CompilerVersion::advertisesPhp84CurlOptionConstants());
            self::assertFalse(CurlExtensionPolicy::advertisesPhp84OptionConstants());
            $ref = \PHPCompiler\ext\curl\CurlConstants::registeredConstants();
            self::assertArrayHasKey('CURLOPT_MAXFILESIZE', $ref);
            self::assertArrayHasKey('CURLINFO_REFERER', $ref);
            self::assertArrayNotHasKey('CURLOPT_TCP_KEEPCNT', $ref);
            self::assertArrayNotHasKey('CURLOPT_PREREQFUNCTION', $ref);
            self::assertArrayNotHasKey('CURLOPT_SERVER_RESPONSE_TIMEOUT', $ref);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        putenv('PHP_COMPILER_PROFILE=8.4');
        putenv('PHP_COMPILER_ENABLE_CURL=1');
        try {
            self::assertTrue(\PHPCompiler\CompilerVersion::advertisesPhp84CurlOptionConstants());
            self::assertTrue(CurlExtensionPolicy::advertisesPhp84OptionConstants());
            $fwd = \PHPCompiler\ext\curl\CurlConstants::registeredConstants();
            self::assertArrayHasKey('CURLOPT_TCP_KEEPCNT', $fwd);
            self::assertArrayHasKey('CURLOPT_SERVER_RESPONSE_TIMEOUT', $fwd);
            self::assertSame(326, $fwd['CURLOPT_TCP_KEEPCNT']);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testCurlVersionFeatureListProfileGate(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE');
        try {
            self::assertFalse(\PHPCompiler\CompilerVersion::advertisesCurlVersionFeatureList());
            self::assertArrayNotHasKey('feature_list', \PHPCompiler\ext\curl\VmCurlCore::versionInfo());
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(\PHPCompiler\CompilerVersion::advertisesCurlVersionFeatureList());
            $info = \PHPCompiler\ext\curl\VmCurlCore::versionInfo();
            self::assertArrayHasKey('feature_list', $info);
            self::assertIsArray($info['feature_list']);
            self::assertArrayHasKey('http2', $info['feature_list']);
            self::assertIsBool($info['feature_list']['http2']);
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }

        putenv('PHP_COMPILER_PROFILE=8.3');
        try {
            self::assertFalse(\PHPCompiler\CompilerVersion::advertisesCurlVersionFeatureList());
            self::assertArrayNotHasKey('feature_list', \PHPCompiler\ext\curl\VmCurlCore::versionInfo());
        } finally {
            if (false === $prev || null === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
