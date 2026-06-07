<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * openssl extension module skeleton registration (issue #7000).
 *
 * @group openssl_module_skeleton
 */
final class OpensslModuleTest extends TestCase
{
    public function test_openssl_module_skeleton_functions_and_constants(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['openssl_encrypt', 'openssl_decrypt', 'openssl_sign', 'openssl_get_cipher_methods', 'openssl_pkey_new'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('openssl_encrypt');
echo (int) function_exists('openssl_decrypt');
echo (int) function_exists('openssl_sign');
echo (int) function_exists('openssl_get_cipher_methods');
echo (int) function_exists('openssl_pkey_new');
echo (int) defined('OPENSSL_RAW_DATA');
echo OPENSSL_RAW_DATA;
echo (int) defined('OPENSSL_ZERO_PADDING');
echo OPENSSL_ZERO_PADDING;
PHP;
        $block = $runtime->parseAndCompile($code, 'openssl_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('111111112', ob_get_clean());
    }

    public function test_openssl_encrypt_stub_throws_logic_exception(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\openssl\openssl_encrypt();
        $frame = $fn->getFrame($runtime->vmContext);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('openssl_encrypt() is not implemented in this compiler build (issue #3324)');
        $fn->execute($frame);
    }
}
