<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\SourcePreprocessor\PropertyHooks;
use PHPUnit\Framework\TestCase;

/** Property-hook reference profile gate (#12574). */
final class PropertyHookReferenceProfileTest extends TestCase
{
    public function testSupportsPropertyHooksFalseOnReferenceProfile(): void
    {
        $this->assertFalse(CompilerVersion::supportsPropertyHooks());
    }

    public function testRejectorThrowsOnDefaultInitializerHook(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHookRejector::UNEXPECTED_ARROW_MESSAGE);
        PropertyHookRejector::reject(
            <<<'PHP'
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
PHP,
            'default_initializer.php'
        );
    }

    public function testRejectorThrowsOnHookBlockWithoutInitializer(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $this->expectException(\PHPCompiler\Compiler\CompileFatal::class);
        $this->expectExceptionMessage(PropertyHookRejector::UNEXPECTED_BRACE_MESSAGE);
        PropertyHookRejector::reject(
            <<<'PHP'
<?php
class C {
    public int $x {
        get { return $this->x; }
    }
}
PHP,
            'hook_block.php'
        );
    }

    public function testRuntimeRejectsPropertyHookSyntax(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $runtime = new Runtime();
        try {
            $runtime->parseAndCompile(
                file_get_contents(__DIR__.'/../repro/maintainer_gap_property_hook_default_initializer.php'),
                'maintainer_gap_property_hook_default_initializer.php'
            );
            $this->fail('Expected compile failure');
        } catch (\PHPCompiler\Compiler\CompileFatal $e) {
            $this->assertStringContainsString('syntax error', strtolower($e->getMessage()));
        }
    }

    public function testLocateFirstPropertyHookViolationFindsInitializerCase(): void
    {
        if (CompilerVersion::supportsPropertyHooks()) {
            $this->markTestSkipped('property hooks enabled on PHP 8.4.0+ target');
        }
        $located = (new PropertyHooks())->locateFirstPropertyHookViolation(
            <<<'PHP'
<?php
class C {
    public string $label = 'default' {
        get => $this->label;
    }
}
PHP
        );
        $this->assertNotNull($located);
        [$line, $message] = $located;
        $this->assertSame(4, $line);
        $this->assertSame(PropertyHookRejector::UNEXPECTED_ARROW_MESSAGE, $message);
    }
}
