<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * gd extension module skeleton registration (issue #7407).
 *
 * @group gd_skeleton
 */
final class GdModuleSkeletonTest extends TestCase
{
    public function test_gd_module_skeleton_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['imagecreate', 'imagecreatetruecolor', 'imagealphablending', 'imagesavealpha', 'imagecolorallocatealpha', 'imageantialias', 'imagesetthickness'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('imagecreate');
echo (int) function_exists('imagecreatetruecolor');
echo (int) function_exists('imagealphablending');
echo (int) function_exists('imagesavealpha');
echo (int) function_exists('imagecolorallocatealpha');
echo (int) function_exists('imageantialias');
echo (int) function_exists('imagesetthickness');
echo (int) extension_loaded('gd');
PHP;
        $block = $runtime->parseAndCompile($code, 'gd_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11111111', ob_get_clean());
    }

    public function test_imagecreate_stub_throws_error(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\gd\imagecreate();
        $frame = $fn->getFrame($runtime->vmContext);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('imagecreate() is not implemented in this compiler build (issue #3496)');
        $fn->execute($frame);
    }
}
