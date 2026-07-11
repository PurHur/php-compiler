<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\BuiltinStubEnumTestSkip;
use PHPCompiler\VM\ScriptExit;
use PHPUnit\Framework\TestCase;

/** @covers issue #7294 */
final class ExitStatusEnumTest extends TestCase
{
    use BuiltinStubEnumTestSkip;

    public function testExitStatusBuiltinEnumExists(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(enum_exists('ExitStatus', false));
echo "\n";
var_export(ExitStatus::Success->name);
echo "\n";
var_export(ExitStatus::Success->value);
echo "\n";
var_export(ExitStatus::Failure->value);
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'exit_status_enum.php'));
        $this->assertSame("true\n'Success'\n0\n1", ob_get_clean());
    }

    public function testExitAcceptsExitStatusEnumCase(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile('<?php exit(ExitStatus::Failure);', 'exit_status.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(1, $e->status);
        }
        $this->assertSame('', ob_get_clean());
    }

    public function testDieAcceptsExitStatusEnumCase(): void
    {
        $this->skipUnlessBuiltinStubEnumsEnabled();
        $runtime = new Runtime();
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile('<?php die(ExitStatus::Success);', 'die_status.php'));
            $this->fail('Expected ScriptExit');
        } catch (ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame('', ob_get_clean());
    }
}
