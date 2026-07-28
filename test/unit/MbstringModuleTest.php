<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * mbstring module skeleton registration (issue #5695).
 *
 * @group mbstring_module_skeleton
 */
final class MbstringModuleTest extends TestCase
{
    public function test_mbstring_module_skeleton_function(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        self::assertTrue(VmReflection::functionExists($ctx, 'mb_strlen'));
        self::assertTrue(VmReflection::functionExists($ctx, 'mb_check_encoding'));
        self::assertTrue(VmReflection::functionExists($ctx, 'mb_convert_case'));

        $code = <<<'PHP'
<?php
echo (int) function_exists('mb_strlen');
echo mb_strlen('é', 'UTF-8');
echo mb_strlen('hello', 'UTF-8');
echo (int) function_exists('mb_convert_case');
echo mb_convert_case('hello', MB_CASE_UPPER, 'UTF-8');
PHP;
        $block = $runtime->parseAndCompile($code, 'mbstring_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1151HELLO', ob_get_clean());
    }

    /** @group mbstring_module_skeleton */
    public function test_mb_oniguruma_version_constant_registered(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo defined('MB_ONIGURUMA_VERSION') ? 'yes' : 'no';
echo '|';
echo is_string(MB_ONIGURUMA_VERSION) && preg_match('/^\d+\.\d+(\.\d+)?/', MB_ONIGURUMA_VERSION) ? 'ok' : 'bad';
PHP;
        $block = $runtime->parseAndCompile($code, 'mb_oniguruma_version.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('yes|ok', ob_get_clean());
    }
}
