<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Dim-assign on uninitialized typed array properties auto-inits [] (#31770).
 */
final class UninitTypedArrayDimAssignTest extends TestCase
{
    /**
     * @covers issue #31770
     */
    public function testDimAssignAndAppendAutoInitTypedArrayProperty(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__) . '/repro/maintainer_gap_uninit_typed_array_append.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_uninit_typed_array_append.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "idx=array (\n  0 => 1,\n)\n"
            ."append=array (\n  0 => 3,\n)\n"
            ."npush=array (\n  0 => 2,\n)\n"
            ."string_concat=Typed property C::\$s must not be accessed before initialization\n"
            ."int_add=Typed property C::\$i must not be accessed before initialization\n"
            ."bare=Typed property C::\$a must not be accessed before initialization\n",
            $out
        );
    }
}
