<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #22241 */
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
}
