<?php

declare(strict_types=1);

namespace PHPCompiler\Test;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * ??= on uninitialized static properties must persist on read-back (#32035).
 *
 * php-src: Zend/zend_execute.c ZEND_ASSIGN_OP / ZEND_COALESCE on static properties.
 */
final class StaticPropertyCoalesceAssign32035Test extends TestCase
{
    /** @covers issue #32035 */
    public function testVmStaticPropertyCoalesceAssignPersists(): void
    {
        $runtime = new Runtime();
        $code = file_get_contents(
            dirname(__DIR__).'/repro/maintainer_gap_static_coalesce_assign.php'
        );
        $this->assertNotFalse($code);
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'maintainer_gap_static_coalesce_assign.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("int(7)\nint(7)\n", $out);
    }

    /** @covers issue #32035 */
    public function testVmTypedStaticPropertyCoalesceAssignPersists(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class S32035Vm
{
    public static int $y;
}
S32035Vm::$y ??= 4;
echo S32035Vm::$y, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'typed_static_coalesce_assign.php'));
        $out = (string) ob_get_clean();
        $this->assertSame("4\n", $out);
    }
}
