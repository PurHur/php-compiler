<?php

declare(strict_types=1);

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Issue #4682 — enum case `in` array on VM. */
final class VmEnumInOperatorTest extends TestCase
{
    public function testBackedEnumCaseInArray(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: string { case A = 'a'; case B = 'b'; }
$e = E::A;
var_dump($e in [E::A, E::B]);
var_dump($e in [E::B]);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'enum_in.php'));
        $output = ob_get_clean();
        $this->assertSame("bool(true)\nbool(false)\n", $output);
    }
}
