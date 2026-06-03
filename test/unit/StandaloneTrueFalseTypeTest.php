<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Standalone `true`/`false` type parameters (issue #4784). */
final class StandaloneTrueFalseTypeTest extends TestCase
{
    public function testLiteralTrueParameterAcceptsBoolRejectsInt(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function accepts_true(true $x): void { echo "ok\n"; }
accepts_true(true);
try { accepts_true(1); } catch (Throwable $e) { echo get_class($e) . "\n"; }
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'standalone_true.php'));
        $out = ob_get_clean();
        $this->assertSame("ok\nTypeError\n", $out);
    }

    public function testLiteralFalseParameterAcceptsBoolRejectsInt(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function accepts_false(false $x): void { echo "ok\n"; }
accepts_false(false);
try { accepts_false(0); } catch (Throwable $e) { echo get_class($e) . "\n"; }
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'standalone_false.php'));
        $out = ob_get_clean();
        $this->assertSame("ok\nTypeError\n", $out);
    }

    public function testLiteralTrueReturnRejectsInt(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function returns_true(): true { return true; }
function bad(): true { return 1; }
returns_true();
try { bad(); } catch (Throwable $e) { echo get_class($e) . "\n"; }
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'standalone_true_return.php'));
        $out = ob_get_clean();
        $this->assertSame("TypeError\n", $out);
    }
}
