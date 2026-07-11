<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * filter module skeleton registration (issue #5839).
 *
 * @group filter_module_skeleton
 */
final class FilterModuleTest extends TestCase
{
    public function test_filter_module_skeleton_functions(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'filter_var'));
        self::assertTrue(VmReflection::functionExists($ctx, 'filter_input'));
        self::assertTrue(VmReflection::functionExists($ctx, 'filter_list'));
        self::assertTrue(VmReflection::functionExists($ctx, 'filter_id'));

        $code = <<<'PHP'
<?php
echo (int) function_exists('filter_var');
echo (int) function_exists('filter_list');
echo (int) function_exists('filter_id');
echo filter_var('42', FILTER_VALIDATE_INT);
echo filter_id('int');
echo count(filter_list());
PHP;
        $block = $runtime->parseAndCompile($code, 'filter_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1114225721', ob_get_clean());
    }
}
