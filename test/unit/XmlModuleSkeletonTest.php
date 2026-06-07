<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmReflection;
use PHPUnit\Framework\TestCase;

/**
 * xml extension module skeleton registration (issue #7406).
 *
 * @group xml_parser_skeleton
 */
final class XmlModuleSkeletonTest extends TestCase
{
    public function test_xml_module_skeleton_functions_and_extension_loaded(): void
    {
        $runtime = new Runtime();
        $ctx = $runtime->vmContext;

        foreach (['xml_parser_create', 'xml_parse'] as $fn) {
            self::assertTrue(VmReflection::functionExists($ctx, $fn), $fn);
        }

        $code = <<<'PHP'
<?php
echo (int) function_exists('xml_parser_create');
echo (int) function_exists('xml_parse');
echo (int) extension_loaded('xml');
PHP;
        $block = $runtime->parseAndCompile($code, 'xml_module.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('111', ob_get_clean());
    }

    public function test_xml_parser_create_stub_throws_error(): void
    {
        $runtime = new Runtime();
        $fn = new \PHPCompiler\ext\xml\xml_parser_create();
        $frame = $fn->getFrame($runtime->vmContext);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('xml_parser_create() is not implemented in this compiler build (issue #3494)');
        $fn->execute($frame);
    }
}
