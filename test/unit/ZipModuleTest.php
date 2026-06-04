<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * zip extension module skeleton registration (issue #5869).
 *
 * @group zip_module_skeleton
 */
final class ZipModuleTest extends TestCase
{
    public function test_zip_module_skeleton_class(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::classExists($ctx, 'ZipArchive'));

        $code = <<<'PHP'
<?php
echo (int) class_exists('ZipArchive', false);
PHP;
        $block = $runtime->parseAndCompile($code, 'zip_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1', ob_get_clean());
    }
}
