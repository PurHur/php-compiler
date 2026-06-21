<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** list() / [] destructuring from string RHS yields unset slots (#10486). */
final class ListDestructStringTest extends TestCase
{
    public function testVmLeavesSlotsUnsetForStringRhs(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
[$a, $b] = 'ab';
echo (int) isset($a), (int) isset($b), "\n";
var_export([$a, $b]);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'list_destructure_string.php'));
        $this->assertSame("00\narray (\n  0 => NULL,\n  1 => NULL,\n)", ob_get_clean());
    }
}
