<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * xmlrpc extension encode/decode + request/type helpers (issue #6579, #18503, #22254).
 *
 * @group xmlrpc
 */
final class XmlrpcModuleTest extends TestCase
{
    public function test_xmlrpc_withheld_on_reference_profile(): void
    {
        self::assertFalse(CompilerVersion::supportsXmlrpc());

        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach ([
            'xmlrpc_encode',
            'xmlrpc_decode',
            'xmlrpc_encode_request',
            'xmlrpc_decode_request',
            'xmlrpc_is_fault',
            'xmlrpc_get_type',
            'xmlrpc_set_type',
        ] as $fn) {
            self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('xmlrpc_encode');
echo (int) function_exists('xmlrpc_decode');
echo (int) function_exists('xmlrpc_encode_request');
echo (int) extension_loaded('xmlrpc');
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlrpc_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0000', ob_get_clean());
    }

    public function test_xmlrpc_registered_and_extension_loaded_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            self::assertTrue(CompilerVersion::supportsXmlrpc());

            $runtime = new Runtime();
            $ctx = $runtime->vmContext;

            foreach ([
                'xmlrpc_encode',
                'xmlrpc_decode',
                'xmlrpc_encode_request',
                'xmlrpc_decode_request',
                'xmlrpc_is_fault',
                'xmlrpc_get_type',
                'xmlrpc_set_type',
            ] as $fn) {
                self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
            }

            $code = <<<'PHP'
<?php
echo (int) function_exists('xmlrpc_encode');
echo (int) function_exists('xmlrpc_decode');
echo (int) function_exists('xmlrpc_encode_request');
echo (int) extension_loaded('xmlrpc');
PHP;
            $block = $runtime->parseAndCompile($code, 'xmlrpc_module.php');
            ob_start();
            $runtime->run($block);
            self::assertSame('1111', ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_xmlrpc_struct_and_scalar_roundtrip_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(__DIR__.'/../repro/maintainer_gap_xmlrpc_roundtrip.php');
            $block = $runtime->parseAndCompile($code, 'maintainer_gap_xmlrpc_roundtrip.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            self::assertStringContainsString("true\n", $out);
            self::assertStringContainsString("demo.add\n", $out);
            self::assertStringContainsString("params_ok\n", $out);
            self::assertStringContainsString("42\n", $out);
            self::assertStringContainsString("invalid_false\n", $out);
            self::assertStringContainsString("funcs_ok\n", $out);
            self::assertStringContainsString("ext_ok\n", $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function test_xmlrpc_request_type_helpers_roundtrip_on_forward_profile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $runtime = new Runtime();
            $code = file_get_contents(__DIR__.'/../repro/issue_22254_xmlrpc_request_types.php');
            $block = $runtime->parseAndCompile($code, 'issue_22254_xmlrpc_request_types.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            self::assertStringContainsString('xmlrpc_encode_request=true', $out);
            self::assertStringContainsString('method=demo.add', $out);
            self::assertStringContainsString('params_ok=1', $out);
            self::assertStringContainsString('is_fault=1', $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
