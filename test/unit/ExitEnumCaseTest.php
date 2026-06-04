<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/** @covers issue #5805 */
final class ExitEnumCaseTest extends TestCase
{
    public function testExitBackedEnumCaseThrowsErrorNotBackingCoercion(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
try {
    exit(E::A);
} catch (Error $e) {
    echo $e->getMessage();
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'exit_enum.php'));
        $this->assertSame(
            'Object of class E could not be converted to string',
            ob_get_clean()
        );
    }

    public function testDieBackedEnumCaseThrowsErrorNotBackingCoercion(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
enum E: int { case A = 1; }
try {
    die(E::A);
} catch (Error $e) {
    echo $e->getMessage();
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'die_enum.php'));
        $this->assertSame(
            'Object of class E could not be converted to string',
            ob_get_clean()
        );
    }

    public function testExitIntegerStatusStillTerminates(): void
    {
        $runtime = new Runtime();
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile('<?php exit(42);', 'exit_int.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(42, $e->status);
        }
        $this->assertSame('', ob_get_clean());
    }
}
