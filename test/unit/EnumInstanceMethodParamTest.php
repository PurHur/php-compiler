<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #16227: enum-typed instance method params with inline-new receiver (Zend/zend_execute.c). */
final class EnumInstanceMethodParamTest extends TestCase
{
    public function testInlineNewMethodCallWithEnumTypedParam(): void
    {
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_enum_instance_method_param.php'
        );
        $this->assertNotFalse($code);

        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'maintainer_gap_enum_instance_method_param.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("E\nE\n1\n", $output);
    }

    /** Issue #9684 — enum case ->name in call args must still use property-fetch slot. */
    public function testEnumCasePropertyFetchInCallArgStillUsesRuntimeValue(): void
    {
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
var_dump(E::A->name);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'enum_case_name_call.php');
        ob_start();
        $runtime->run($block);
        $output = ob_get_clean();

        $this->assertSame("string(1) \"A\"\n", $output);
    }
}
