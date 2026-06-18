<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #1358 */
final class NeverReturnTypeTest extends TestCase
{
    public function testNeverFunctionWithExitDoesNotReachCaller(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function stop(): never {
    exit('gone');
}
stop();
echo 'after';
PHP;
        ob_start();
        try {
            $runtime->run($runtime->parseAndCompile($code, 'never_exit.php'));
            $this->fail('Expected ScriptExit');
        } catch (\PHPCompiler\VM\ScriptExit $e) {
            $this->assertSame(0, $e->status);
        }
        $this->assertSame('gone', ob_get_clean());
    }

    public function testNeverRejectsBareReturnAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): never {
    return;
}
PHP;
        $this->expectException(\CompileError::class);
        $runtime->parseAndCompile($code, 'never_bare_return.php');
    }

    public function testNeverRejectsValueReturnAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function f(): never {
    return 1;
}
PHP;
        $this->expectException(\CompileError::class);
        $runtime->parseAndCompile($code, 'never_value_return.php');
    }

    public function testNeverReturnTypeMapsInPhpTypes(): void
    {
        $parser = new \PHPCfg\Parser((new \PhpParser\ParserFactory())->create(\PhpParser\ParserFactory::PREFER_PHP7));
        $script = $parser->parse('<?php function f(): never { exit; }', 't.php');
        $type = \PHPTypes\Type::fromTypeDecl($script->functions[0]->returnType);
        $this->assertSame(\PHPTypes\Type::TYPE_NULL, $type->type);
    }

    public function testNeverImplicitReturnAfterEchoRaisesTypeError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function g(): never {
    echo "bad\n";
}
try {
    g();
    echo "continued\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'never_implicit_echo.php'));
        $this->assertSame(
            "bad\nTypeError: g(): never-returning function must not implicitly return\n",
            ob_get_clean()
        );
    }

    public function testNeverCallSiteDoesNotFallThroughAfterThrowInTry(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function fail(): never {
    throw new Exception('x');
}
try {
    fail();
    echo "after\n";
} catch (Exception $e) {
    echo "caught\n";
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'never_throw_callsite.php'));
        $this->assertSame("caught\n", ob_get_clean());
    }
}
