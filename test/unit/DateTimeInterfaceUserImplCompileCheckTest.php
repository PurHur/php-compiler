<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #18781 */
final class DateTimeInterfaceUserImplCompileCheckTest extends TestCase
{
    public function testClassImplementsDateTimeInterfaceZendFatalStderr(): void
    {
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(<<<'PHP'
<?php
class UserDateTime implements DateTimeInterface {}
PHP, 'datetimeinterface.php');
            $this->fail('expected CompileFatal');
        } catch (CompileFatal $e) {
            $this->assertSame(
                "Fatal error: DateTimeInterface can't be implemented by user classes in datetimeinterface.php on line 2\n",
                $e->zendStderrLine()
            );
        }
    }
}
