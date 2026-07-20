<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * ctype extension VM semantics (issues #6837, #7253).
 *
 * @group ctype_module_skeleton
 */
final class CtypeModuleSkeletonTest extends TestCase
{
    public function test_ctype_module_skeleton_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach ([
            'ctype_alnum',
            'ctype_alpha',
            'ctype_cntrl',
            'ctype_digit',
            'ctype_graph',
            'ctype_lower',
            'ctype_print',
            'ctype_punct',
            'ctype_space',
            'ctype_upper',
            'ctype_xdigit',
        ] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('ctype_alnum');
echo (int) function_exists('ctype_alpha');
echo (int) function_exists('ctype_digit');
echo (int) function_exists('ctype_space');
echo (int) extension_loaded('ctype');
PHP;
        $block = $runtime->parseAndCompile($code, 'ctype_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('11111', ob_get_clean());
    }

    public function test_ctype_alnum_vm_semantics(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) ctype_alnum('abc');
echo (int) ctype_alnum('123');
echo (int) ctype_alnum('');
echo (int) ctype_alnum(' ');
echo (int) ctype_digit(97);
echo (int) ctype_digit(256);
echo (int) ctype_space("\t\n");
PHP;
        $block = $runtime->parseAndCompile($code, 'ctype_semantics.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('1100011', ob_get_clean());
    }

    public function test_ctype_blank_is_not_advertised(): void
    {
        $runtime = new Runtime();
        self::assertFalse(VmReflection::functionExists($runtime->vmContext, 'ctype_blank'));

        $code = <<<'PHP'
<?php
echo function_exists('ctype_blank') ? 'yes' : 'no';
PHP;
        $block = $runtime->parseAndCompile($code, 'ctype_blank_phantom.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('no', ob_get_clean());
    }

    public function test_ctype_enum_case_operands_return_false(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: string { case A = 'abc'; case B = '123'; }
echo (int) ctype_alpha(E::A);
echo (int) ctype_digit(E::B);
echo (int) ctype_alnum(E::A);
echo (int) ctype_alpha('abc');
PHP;
        $block = $runtime->parseAndCompile($code, 'ctype_enum_false.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('0001', ob_get_clean());
    }
}
