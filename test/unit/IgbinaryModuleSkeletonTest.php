<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * igbinary extension module skeleton registration (issue #7033).
 *
 * @group igbinary_module_skeleton
 */
final class IgbinaryModuleSkeletonTest extends TestCase
{
    public function test_igbinary_module_skeleton_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['igbinary_serialize', 'igbinary_unserialize', 'igbinary_pack', 'igbinary_unpack'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('igbinary_serialize');
echo (int) function_exists('igbinary_unserialize');
echo (int) function_exists('igbinary_pack');
echo (int) function_exists('igbinary_unpack');
echo (int) extension_loaded('igbinary');
PHP;
        $block = $runtime->parseAndCompile($code, 'igbinary_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11111', ob_get_clean());
    }

    public function test_igbinary_serialize_stub_throws_error(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\igbinary\igbinary_serialize();
        $frame = $fn->getFrame($runtime->vmContext);
        $value = new VM\Variable();
        $value->string('test');
        $frame->calledArgs = [$value];

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('igbinary_serialize() is not implemented in this compiler build (issue #6573)');
        $fn->execute($frame);
    }
}
