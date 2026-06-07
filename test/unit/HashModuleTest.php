<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * hash extension module skeleton registration (issue #6937).
 *
 * @group hash_module_skeleton
 */
final class HashModuleTest extends TestCase
{
    public function test_hash_module_skeleton_functions(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['hash_init', 'hash_update', 'hash_final', 'hash_copy', 'hash_algos'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('hash_init');
echo (int) function_exists('hash_update');
echo (int) function_exists('hash_final');
echo (int) function_exists('hash_copy');
echo (int) function_exists('hash_algos');
$algos = hash_algos();
sort($algos);
echo implode(',', $algos);
PHP;
        $block = $runtime->parseAndCompile($code, 'hash_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11111md5,sha1,sha256', ob_get_clean());
    }

    public function test_hash_init_stub_throws_error(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\hash\hash_init();
        $frame = $fn->getFrame($runtime->vmContext);
        $algo = new VM\Variable();
        $algo->string('sha256');
        $frame->calledArgs = [$algo];

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('hash_init() is not implemented in this compiler build (issue #3357)');
        $fn->execute($frame);
    }
}
