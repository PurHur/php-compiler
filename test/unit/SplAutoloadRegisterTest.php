<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** spl_autoload_register() VM + JIT stack (issues #1369, #1776). */
final class SplAutoloadRegisterTest extends TestCase
{
    public function testJitLowersRegisterWithoutLogicException(): void
    {
        $code = <<<'PHP'
<?php
function autoload_demo(string $class): void {}
spl_autoload_register('autoload_demo');
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        $this->expectNotToPerformAssertions();
        try {
            $runtime->jitCompileBlock($block);
        } catch (\LogicException $e) {
            if (str_contains($e->getMessage(), 'not implemented for JIT')) {
                $this->fail($e->getMessage());
            }
            throw $e;
        }
    }

    public function testClosureCallbackLoadsClassOnNew(): void
    {
        $code = <<<'PHP'
<?php
spl_autoload_register(function (string $class): void {
    if ('Demo' === $class) {
        class Demo
        {
            public function id(): int
            {
                return 7;
            }
        }
    }
});
echo (new Demo())->id();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame('7', ob_get_clean());
    }

    public function testRegistersCallbackAndLoadsClassOnNew(): void
    {
        $code = <<<'PHP'
<?php
function autoload_demo(string $class): void
{
    if ('Demo' === $class) {
        class Demo
        {
            public function id(): int
            {
                return 9;
            }
        }
    }
}
spl_autoload_register('autoload_demo');
echo (new Demo())->id();
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame('9', ob_get_clean());
    }

    public function testClassExistsInvokesRegisteredAutoloadCallback(): void
    {
        $code = <<<'PHP'
<?php
$loaded = false;
spl_autoload_register(function (string $class) use (&$loaded): void {
    if ('LazyClass' === $class) {
        $loaded = true;
    }
});
class_exists('LazyClass');
var_export($loaded);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame('true', ob_get_clean());
    }

    public function testClassExistsSkipsAutoloadWhenSecondArgFalse(): void
    {
        $code = <<<'PHP'
<?php
$loaded = false;
spl_autoload_register(function (string $class) use (&$loaded): void {
    if ('LazyClass' === $class) {
        $loaded = true;
    }
});
class_exists('LazyClass', false);
var_export($loaded);
PHP;
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
        ob_start();
        $runtime->run($block);
        $this->assertSame('false', ob_get_clean());
    }
}
