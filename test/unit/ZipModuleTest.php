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
        $prev = getenv('PHP_COMPILER_ENABLE_ZIP');
        putenv('PHP_COMPILER_ENABLE_ZIP=1');
        try {
            $runtime = new Runtime();
            $ctx = $runtime->vmContext;

            self::assertTrue(VmReflection::classExists($ctx, 'ZipArchive'));
            self::assertTrue(VmReflection::functionExists($ctx, 'zip_open'));
            self::assertTrue(VmReflection::functionExists($ctx, 'zip_read'));
            self::assertTrue(VmReflection::functionExists($ctx, 'zip_close'));
            self::assertTrue(VmReflection::functionExists($ctx, 'zip_entry_name'));

            $code = <<<'PHP'
<?php
echo (int) class_exists('ZipArchive', false);
echo "\n";
echo (int) function_exists('zip_open');
PHP;
            $block = $runtime->parseAndCompile($code, 'zip_module.php');
            ob_start();
            $runtime->run($block);
            self::assertSame("1\n1", ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_ENABLE_ZIP');
            } else {
                putenv('PHP_COMPILER_ENABLE_ZIP='.$prev);
            }
        }
    }
}
