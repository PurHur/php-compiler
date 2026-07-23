<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #22241, #22308 */
final class FinalPropertyOverrideCheckTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
    }

    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
    }

    public function testPlainFinalPropertyCompilesAndReads(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class ParentF {
    final public string $name = 'a';
}
echo (new ParentF)->name, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'final_plain_ok.php');
        ob_start();
        $runtime->run($block);
        self::assertSame("a\n", ob_get_clean());
    }

    public function testPlainFinalPropertyRejectedOnReferenceProfile(): void
    {
        putenv('PHP_COMPILER_PROFILE');
        unset($_ENV['PHP_COMPILER_PROFILE']);
        self::assertFalse(\PHPCompiler\CompilerVersion::supportsFinalProperties());

        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public final string $x = 'a';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Cannot declare property C::$x final, the final modifier is allowed only for methods, classes, and class constants'
        );
        $runtime->parseAndCompile($code, 'final_plain_reject_ref.php');
    }

    public function testChildCannotOverrideFinalProperty(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class ParentF {
    final public string $name = 'a';
}
class ChildF extends ParentF {
    public string $name = 'b';
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final property ParentF::$name');
        $runtime->parseAndCompile($code, 'final_plain_override.php');
    }

    public function testHookedFinalPropertyStillWorks(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public final string $label {
        get => 'ok';
    }
}
echo (new C)->label;
PHP;
        $block = $runtime->parseAndCompile($code, 'final_hooked_ok.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('ok', ob_get_clean());
    }

    /** @covers issue #22474 */
    public function testChildCannotOverrideFinalSetHook(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public string $x {
        get => 'p';
        final set(string $v) {}
    }
}
class C extends P {
    public string $x {
        get => 'c';
        set(string $v) {}
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final property hook P::$x::set()');
        $runtime->parseAndCompile($code, 'final_hook_set_override.php');
    }

    /** @covers issue #22474 */
    public function testChildCannotOverrideFinalGetHook(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public string $x {
        final get => 'p';
        set(string $v) {}
    }
}
class C extends P {
    public string $x {
        get => 'c';
        set(string $v) {}
    }
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot override final property hook P::$x::get()');
        $runtime->parseAndCompile($code, 'final_hook_get_override.php');
    }

    /** @covers issue #22474 */
    public function testNonFinalHookOverrideStillWorks(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public string $x {
        get => 'p';
        set(string $v) { $this->x = $v; }
    }
}
class C extends P {
    public string $x {
        get => 'c';
        set(string $v) { $this->x = $v; }
    }
}
echo (new C)->x;
PHP;
        $block = $runtime->parseAndCompile($code, 'nonfinal_hook_override.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('c', ob_get_clean());
    }

    /** @covers issue #22474 — final set does not block overriding get alone */
    public function testOverrideGetWhenOnlySetIsFinal(): void
    {
        if (!\PHPCompiler\CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks disabled');
        }
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class P {
    public string $x {
        get => 'p';
        final set(string $v) { $this->x = $v; }
    }
}
class C extends P {
    public string $x {
        get => 'c';
    }
}
echo (new C)->x;
PHP;
        $block = $runtime->parseAndCompile($code, 'final_set_override_get_only.php');
        ob_start();
        $runtime->run($block);
        self::assertSame('c', ob_get_clean());
    }
}
