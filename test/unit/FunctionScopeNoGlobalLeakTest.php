<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #5454 */
final class FunctionScopeNoGlobalLeakTest extends TestCase
{
    public function testUnboundLocalDoesNotReadScriptGlobal(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function warn_capture(int $errno, string $message): bool
{
    echo 'W:', $message, "\n";
    return true;
}
set_error_handler('warn_capture');

$x = 1;
function f(): void {
    var_dump($x);
}
f();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'function_scope_no_global_leak.php'));
        $this->assertSame("W:Undefined variable \$x\nNULL\n", ob_get_clean());
    }

    public function testGlobalImportRestoresScriptGlobalAccess(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$x = 1;
function f() {
    global $x;
    return $x;
}
echo f(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'function_scope_global_import.php'));
        $this->assertSame("1\n", ob_get_clean());
    }
}
