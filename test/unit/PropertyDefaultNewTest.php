<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Property default `new` expressions (issue #3391). */
final class PropertyDefaultNewTest extends TestCase
{
    public function testInstancePropertyDefaultNewIsPerInstance(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {
    public stdClass $inner = new stdClass();
}
$a = new Box();
$b = new Box();
echo ($a->inner instanceof stdClass) ? "1\n" : "0\n";
echo ($a->inner !== $b->inner) ? "1\n" : "0\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'property_default_new.php'));
        $out = ob_get_clean();
        $this->assertSame("1\n1\n", $out);
    }
}
