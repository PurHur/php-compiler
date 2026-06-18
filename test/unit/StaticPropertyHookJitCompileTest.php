<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../LlvmToolchain.php';

/** Static property hooks compile through Runtime (#6931, #9520). */
final class StaticPropertyHookJitCompileTest extends TestCase
{
    public function testStaticPropertyHooksCompile(): void
    {
        $runtime = new Runtime();
        $script = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class Box {
    public static string $label {
        get => self::$v;
        set => self::$v = $value;
    }
    private static ?string $v = null;
}
PHP,
            'static_hooks_jit_compile.php'
        );
        self::assertNotNull($script);
    }

    public function testStaticPropertyHookJitModuleVerify(): void
    {
        if (!LlvmToolchain::isReady(dirname(__DIR__, 2))) {
            $this->markTestSkipped('LLVM 9 toolchain not available');
        }
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            file_get_contents(dirname(__DIR__).'/repro/issue_4807_static_property_hook_jit.php'),
            'issue_4807_static_property_hook_jit.php'
        );
        self::assertNotNull($block);
        $runtime->jitCompileBlock($block);
        $context = $runtime->loadJitContext();
        $verify = new \ReflectionMethod($context, 'compileCommon');
        $verify->setAccessible(true);
        $verify->invoke($context);
        $this->addToAssertionCount(1);
    }
}
