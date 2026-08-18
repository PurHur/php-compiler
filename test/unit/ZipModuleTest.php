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

    public function test_zip_open_named_filename_matches_positional(): void
    {
        $prev = getenv('PHP_COMPILER_ENABLE_ZIP');
        putenv('PHP_COMPILER_ENABLE_ZIP=1');
        try {
            $runtime = new Runtime();
            $code = <<<'PHP'
<?php
$n = [];
foreach ((new ReflectionFunction('zip_open'))->getParameters() as $p) {
    $n[] = $p->getName();
}
echo implode(',', $n), "\n";
echo var_export(@zip_open('/no/such.zip'), true), "\n";
echo var_export(@zip_open(filename: '/no/such.zip'), true), "\n";
$c = [];
foreach ((new ReflectionFunction('zip_entry_close'))->getParameters() as $p) {
    $c[] = $p->getName();
}
echo implode(',', $c), "\n";
try {
    zip_entry_close(zip_entry: false);
} catch (Throwable $e) {
    echo 'zip_entry:', $e->getMessage(), "\n";
}
try {
    zip_entry_close(zip_ent: false);
} catch (Throwable $e) {
    echo 'zip_ent:', $e->getMessage(), "\n";
}
PHP;
            $block = $runtime->parseAndCompile($code, 'zip_named.php');
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            self::assertStringContainsString("filename\n", $out);
            self::assertStringContainsString("false\nfalse\n", $out);
            self::assertStringContainsString("zip_entry\n", $out);
            self::assertStringContainsString('zip_entry:zip_entry_close(): Argument #1 ($zip_entry) must be of type resource, bool given', $out);
            self::assertStringContainsString('zip_ent:Unknown named parameter $zip_ent', $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_ENABLE_ZIP');
            } else {
                putenv('PHP_COMPILER_ENABLE_ZIP='.$prev);
            }
        }
    }
}
