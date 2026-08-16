<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** is_* / is_callable Reflection mixed $value (#28312, #30242, type.stub.php). */
final class IsStarReflectionMixedTest extends TestCase
{
    public function testReflectionMatchesZendStubs(): void
    {
        $code = file_get_contents(__DIR__.'/../repro/issue_28312_is_star_reflection.php');
        $this->assertNotFalse($code);
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue_28312.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "is_numeric\tvalue:mixed\t→\tbool\n"
            ."is_string\tvalue:mixed\t→\tbool\n"
            ."is_int\tvalue:mixed\t→\tbool\n"
            ."is_integer\tvalue:mixed\t→\tbool\n"
            ."is_float\tvalue:mixed\t→\tbool\n"
            ."is_double\tvalue:mixed\t→\tbool\n"
            ."is_bool\tvalue:mixed\t→\tbool\n"
            ."is_null\tvalue:mixed\t→\tbool\n"
            ."is_array\tvalue:mixed\t→\tbool\n"
            ."is_object\tvalue:mixed\t→\tbool\n"
            ."is_resource\tvalue:mixed\t→\tbool\n"
            ."is_scalar\tvalue:mixed\t→\tbool\n"
            ."is_callable\tvalue:mixed,syntax_only:bool=false,callable_name:NONE&=NULL\t→\tbool\n"
            ."is_int_named=true\n"
            ."is_callable_omit=true\n",
            ob_get_clean()
        );
    }
}
