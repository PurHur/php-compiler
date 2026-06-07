<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * msgpack extension module skeleton registration (issue #7032).
 *
 * @group msgpack_module_skeleton
 */
final class MsgpackModuleSkeletonTest extends TestCase
{
    public function test_msgpack_module_skeleton_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['msgpack_pack', 'msgpack_unpack'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('msgpack_pack');
echo (int) function_exists('msgpack_unpack');
echo (int) extension_loaded('msgpack');
PHP;
        $block = $runtime->parseAndCompile($code, 'msgpack_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('111', ob_get_clean());
    }

    public function test_msgpack_pack_stub_throws_error(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\msgpack\msgpack_pack();
        $frame = $fn->getFrame($runtime->vmContext);
        $value = new VM\Variable();
        $value->string('test');
        $frame->calledArgs = [$value];

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('msgpack_pack() is not implemented in this compiler build (issue #6551)');
        $fn->execute($frame);
    }
}
