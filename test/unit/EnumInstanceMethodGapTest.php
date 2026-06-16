<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #9002: backed enum instance method dispatch on case objects (Zend/zend_enum.c). */
final class EnumInstanceMethodGapTest extends TestCase
{
    public function testBackedEnumCaseInstanceMethodReturnsName(): void
    {
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_enum_instance_method.php'
        );
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_enum_instance_method.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("A\n", $output);
    }

    public function testUnitEnumCaseInstanceMethod(): void
    {
        $code = <<<'PHP'
<?php
enum U {
    case A;
    public function tag(): string { return $this->name; }
}
echo U::A->tag();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_unit_method.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame('A', $output);
    }
}
