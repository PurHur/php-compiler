<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit\VM;

use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Issue #1885: return static::method() from instance method. */
final class NestedReturnCallTest extends TestCase
{
    public function testOuterFunctionUsesFuncCallExecReturnBeforeReturn(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function inner(): string { return 'hi'; }
function outer(): string { return inner(); }
PHP;
        $main = $runtime->parseAndCompile($code, 'opcodes.php');
        $outer = $this->findFunctionBlock($main, 'outer');
        $this->assertNotNull($outer);
        $n = $outer->nOpCodes;
        $this->assertGreaterThanOrEqual(2, $n);
        $this->assertSame(OpCode::TYPE_FUNCCALL_EXEC_RETURN, $outer->opCodes[$n - 2]->type);
        $this->assertSame(OpCode::TYPE_RETURN, $outer->opCodes[$n - 1]->type);
        $this->assertSame($outer->opCodes[$n - 2]->arg1, $outer->opCodes[$n - 1]->arg1);
    }

    public function testReturnStaticCallFromInstanceMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class G {
    public static function tag(): string { return 'hi'; }
    public function via(): string { return static::tag(); }
}
echo (new G())->via();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'nested.php'));
        $this->assertSame('hi', ob_get_clean());
    }

    public function testReturnLiteralAfterStaticCallFromInstanceMethod(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class G {
    public static function tag(): string { return 'hi'; }
    public function via(): string { static::tag(); return 'ok'; }
}
echo (new G())->via();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'literal_after.php'));
        $this->assertSame('ok', ob_get_clean());
    }

    public function testReturnNestedUserFunctionCall(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
function inner(): string { return 'hi'; }
function outer(): string { return inner(); }
echo outer();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'nested_func.php'));
        $this->assertSame('hi', ob_get_clean());
    }

    public function testStaticMethodReceivesCallArgs(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Router
{
    public static function fromConfig(array $config): self
    {
        return new self($config);
    }

    public function appName(): string
    {
        return $this->config['app_name'] ?? 'MiniWebApp';
    }

    /** @var array<string, mixed> */
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }
}
$router = Router::fromConfig(['app_name' => 'TestApp']);
echo $router->appName();
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'static_method_args.php'));
        $this->assertSame('TestApp', ob_get_clean());
    }

    public function testLateStaticBindingPhptShape(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Greeter {
    public static function tag(): string {
        return 'hi';
    }
    public function viaStatic(): string {
        return static::tag();
    }
    public function className(): string {
        return static::class;
    }
}
$g = new Greeter();
echo $g->viaStatic(), "\n";
echo $g->className(), "\n";
echo Greeter::tag(), "\n";
class Base {
    public static function who(): string {
        return static::class;
    }
}
class Child extends Base {}
echo Base::who(), "\n";
echo Child::who(), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'late_static_binding.php'));
        $this->assertSame("hi\nGreeter\nhi\nBase\nChild\n", ob_get_clean());
    }

    private function findFunctionBlock(?\PHPCompiler\Block $main, string $lcName): ?\PHPCompiler\Block
    {
        if (null === $main) {
            return null;
        }
        foreach ($main->opCodes as $op) {
            if (OpCode::TYPE_FUNCDEF !== $op->type || null === $op->block1) {
                continue;
            }
            $func = $op->block1->func;
            $funcName = is_string($func->name) ? $func->name : $func->name->value;
            if (null !== $func && strtolower($funcName) === $lcName) {
                return $op->block1;
            }
        }

        return null;
    }
}
