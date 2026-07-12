<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * xmlrpc extension encode/decode (issue #6579).
 *
 * @group xmlrpc
 */
final class XmlrpcModuleTest extends TestCase
{
    public function test_xmlrpc_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['xmlrpc_encode', 'xmlrpc_decode'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('xmlrpc_encode');
echo (int) function_exists('xmlrpc_decode');
echo (int) extension_loaded('xmlrpc');
PHP;
        $block = $runtime->parseAndCompile($code, 'xmlrpc_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('111', ob_get_clean());
    }

    public function test_xmlrpc_struct_and_scalar_roundtrip(): void
    {
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
    }
}
