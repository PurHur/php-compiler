<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #3296 */
final class StringableTest extends TestCase
{
    public function testInterfaceExistsBuiltin(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo interface_exists('Stringable') ? '1' : '0', "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'stringable_exists.php'));
        $this->assertSame("1\n", ob_get_clean());
    }

    public function testConcreteClassMissingToStringFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Bad implements Stringable {}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Stringable::__toString');
        $runtime->parseAndCompile($code, 'stringable_bad.php');
    }

    public function testAbstractClassMayOmitToString(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
abstract class Base implements Stringable {}
PHP;
        $runtime->parseAndCompile($code, 'stringable_abstract.php');
        $this->addToAssertionCount(1);
    }

    public function testCastInvokesToString(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Ok implements Stringable {
    public function __toString(): string {
        return 'cast-ok';
    }
}
echo (string) new Ok();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'stringable_cast.php'));
        $this->assertSame('cast-ok', ob_get_clean());
    }
}
