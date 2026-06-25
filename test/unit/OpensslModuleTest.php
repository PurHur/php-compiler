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

        foreach (['openssl_encrypt', 'openssl_decrypt', 'openssl_sign', 'openssl_get_cipher_methods', 'openssl_get_md_methods', 'openssl_pkey_new', 'openssl_cipher_iv_length', 'openssl_cipher_key_length', 'openssl_digest'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('openssl_encrypt');
echo (int) function_exists('openssl_decrypt');
echo (int) function_exists('openssl_sign');
echo (int) function_exists('openssl_get_cipher_methods');
echo (int) function_exists('openssl_get_md_methods');
echo (int) function_exists('openssl_pkey_new');
echo (int) function_exists('openssl_cipher_iv_length');
echo (int) function_exists('openssl_cipher_key_length');
echo (int) function_exists('openssl_digest');
echo (int) defined('OPENSSL_RAW_DATA');
echo OPENSSL_RAW_DATA;
echo (int) defined('OPENSSL_ZERO_PADDING');
echo OPENSSL_ZERO_PADDING;
echo (int) defined('OPENSSL_ALGO_SHA256');
echo OPENSSL_ALGO_SHA256;
PHP;
        $block = $runtime->parseAndCompile($code, 'openssl_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('111111111111217', ob_get_clean());
    }

    public function test_openssl_cipher_key_length_aes_256_cbc(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\openssl\openssl_cipher_key_length();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = (static function () use ($runtime) {
            $v = new \PHPCompiler\VM\Variable();
            $v->string('aes-256-cbc');

            return $v;
        })();
        $frame->returnVar = new \PHPCompiler\VM\Variable();
        $fn->execute($frame);
        self::assertSame(\PHPCompiler\VM\Variable::TYPE_INTEGER, $frame->returnVar->type);
        self::assertSame(32, $frame->returnVar->toInt());
    }

    public function test_openssl_cipher_iv_length_aes_256_cbc(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\openssl\openssl_cipher_iv_length();
        $frame = $fn->getFrame($runtime->vmContext);
        $frame->calledArgs[] = (static function () use ($runtime) {
            $v = new \PHPCompiler\VM\Variable();
            $v->string('aes-256-cbc');

            return $v;
        })();
        $frame->returnVar = new \PHPCompiler\VM\Variable();
        $fn->execute($frame);
        self::assertSame(\PHPCompiler\VM\Variable::TYPE_INTEGER, $frame->returnVar->type);
        self::assertSame(16, $frame->returnVar->toInt());
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
