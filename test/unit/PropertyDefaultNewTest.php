<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Compiler\NewWithoutParensCompileCheck;
use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Property default `new` — reference profile rejects; 8.4 forward profile allows instance (#18040). */
final class PropertyDefaultNewTest extends TestCase
{
    public function testInstanceTypedPropertyDefaultNewCompileErrors(): void
    {
        if (CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public stdClass $inner = new stdClass();
}
PHP, 'property_default_new_instance_typed.php');
            $this->assertNotNull($block);

            return;
        }
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public stdClass $inner = new stdClass();
}
PHP, 'property_default_new_instance_typed.php');
    }

    public function testInstanceUntypedPropertyDefaultNewCompileErrors(): void
    {
        if (CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public $inner = new stdClass();
}
PHP, 'property_default_new_instance_untyped.php');
            $this->assertNotNull($block);

            return;
        }
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public $inner = new stdClass();
}
PHP, 'property_default_new_instance_untyped.php');
    }

    public function testStaticPropertyDefaultNewCompileErrors(): void
    {
        if (CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public static $x = new stdClass();
}
PHP, 'property_default_new_static.php');
            $this->assertNotNull($block);

            return;
        }
        $runtime = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(NewWithoutParensCompileCheck::MESSAGE);
        $runtime->parseAndCompile(<<<'PHP'
<?php
class C {
    public static $x = new stdClass();
}
PHP, 'property_default_new_static.php');
    }

    public function testPromotedTypedPropertyDefaultNewStillCompiles(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(<<<'PHP'
<?php
class Box {
    public function __construct(public array $items = []) {}
}
class C {
    public function __construct(public Box $y = new Box([])) {}
}
PHP, 'property_default_new_promoted.php');
        $this->assertNotNull($block);
    }

    public function testSupportsPropertyDefaultObjectExpressionsTrueOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsPropertyDefaultObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testSupportsPropertyDefaultObjectExpressionsFalseOnReferenceProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.2');
        try {
            $this->assertFalse(CompilerVersion::supportsPropertyDefaultObjectExpressions());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testInstanceTypedPropertyDefaultNewRunsOnForwardProfile(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            if (!CompilerVersion::supportsPropertyDefaultObjectExpressions()) {
                $this->markTestSkipped('forward profile gate unavailable');
            }
            $runtime = new Runtime();
            $block = $runtime->parseAndCompile(
                file_get_contents(dirname(__DIR__).'/repro/maintainer_gap_property_initializer_new.php'),
                'maintainer_gap_property_initializer_new.php'
            );
            ob_start();
            $runtime->run($block);
            $out = ob_get_clean();
            $this->assertSame("ok\n", $out);
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }
}
