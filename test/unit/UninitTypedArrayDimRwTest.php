<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Dim ++/+= on uninitialized typed array properties Error; assign/append auto-init (#31784).
 */
final class UninitTypedArrayDimRwTest extends TestCase
{
    /**
     * @covers issue #31784
     */
    public function testDimRwErrorsAndDimAssignStillAutoInits(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__) . '/repro/maintainer_gap_inc_dim_uninit_typed.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_inc_dim_uninit_typed.php'));
        $out = (string) ob_get_clean();
        $this->assertSame(
            "inc=Error:Typed property C::\$a must not be accessed before initialization\n"
            ."add=Error:Typed property C::\$a must not be accessed before initialization\n"
            ."preinc=Error:Typed property C::\$a must not be accessed before initialization\n"
            ."array (\n  0 => 1,\n)\n"
            ."assign=ok\n"
            ."array (\n  0 => 2,\n)\n"
            ."append=ok\n"
            ."after\n",
            $out
        );
    }
}
