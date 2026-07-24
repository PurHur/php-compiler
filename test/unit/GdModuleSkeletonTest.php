<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\gd\GdExtensionPolicy;
use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * gd extension module registration gated on host php-gd (#7407, #22740).
 *
 * @group gd_skeleton
 */
final class GdModuleSkeletonTest extends TestCase
{
    public function test_gd_module_skeleton_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;
        $fns = [
            'imagecreate',
            'imagecreatetruecolor',
            'imagealphablending',
            'imagesavealpha',
            'imagecolorallocatealpha',
            'imageantialias',
            'imagesetthickness',
        ];

        if (!GdExtensionPolicy::advertisesExtension()) {
            foreach ($fns as $fn) {
                self::assertFalse(VmReflection::functionExists($ctx, $fn), $fn);
            }
            self::assertFalse(ext\standard\ModuleRegistry::extensionLoaded('gd'));

            $code = <<<'PHP'
<?php
echo (int) function_exists('imagecreate');
echo (int) extension_loaded('gd');
PHP;
            $block = $runtime->parseAndCompile($code, 'gd_module.php');
            ob_start();
            $runtime->run($block);
            self::assertSame('00', ob_get_clean());

            return;
        }

        foreach ($fns as $fn) {
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
}
