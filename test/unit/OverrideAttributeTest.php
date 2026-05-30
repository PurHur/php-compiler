<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3211 */
final class OverrideAttributeTest extends TestCase
{
    public function testInvalidOverrideFailsAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function foo(): void {}
}
class Child extends Base {
    #[\Override]
    public function bar(): void {}
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Child::bar() has #[\Override] attribute, but no matching parent method exists');
        $runtime->parseAndCompile($code, 'override_invalid.php');
    }

    public function testValidOverrideCompiles(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Base {
    public function foo(): void {}
}
class Child extends Base {
    #[\Override]
    public function foo(): void {}
}
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'override_valid.php'));
        $this->assertSame("ok\n", ob_get_clean());
    }
}
