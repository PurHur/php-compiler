<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Dim-assign on uninitialized non-array typed properties TypeError (#31819).
 */
final class AssignDimNonarrayTypedUninitTest extends TestCase
{
    /**
     * @covers issue #31819
     */
    public function testDimAssignNonArrayTypedPropertyTypeError(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__) . '/repro/maintainer_gap_assign_dim_nonarray_typed.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_assign_dim_nonarray_typed.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "uninit_string: TypeError: Cannot auto-initialize an array inside property S1::\$s of type string\n"
            ."append_uninit_string: TypeError: Cannot auto-initialize an array inside property S2::\$s of type string\n"
            ."uninit_int: TypeError: Cannot auto-initialize an array inside property I1::\$x of type int\n"
            ."uninit_bool: TypeError: Cannot auto-initialize an array inside property B1::\$b of type bool\n"
            ."uninit_float: TypeError: Cannot auto-initialize an array inside property F1::\$f of type float\n"
            ."uninit_object: TypeError: Cannot auto-initialize an array inside property O1::\$o of type object\n"
            ."[1] uninit_array: ok\n",
            $out
        );
    }
}
