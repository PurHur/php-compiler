<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * ctype extension module skeleton registration (issue #6837).
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

    public function test_ctype_alnum_stub_throws_error(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\ctype\ctype_alnum();
        $frame = $fn->getFrame($runtime->vmContext);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('ctype_alnum() is not implemented in this compiler build (issue #3381)');
        $fn->execute($frame);
    }
}
