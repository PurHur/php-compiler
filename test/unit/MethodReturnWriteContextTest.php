<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../BaseTest.php';

/**
 * #26436 — method/function call results are illegal write targets (zend_compile.c).
 */
class MethodReturnWriteContextTest extends TestCase
{
    private function compileSnippet(string $code): void
    {
        $factory = new \PhpParser\ParserFactory();
        $parser = new \PHPCfg\Parser($factory->createForNewestSupportedVersion());
        $script = $parser->parse('<?php ' . $code, 'Command line code');
        (new Compiler())->compile($script);
    }

    public function testInstanceMethodReturnAssignIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("Can't use method return value in write context");
        $this->compileSnippet(<<<'PHP'
class C {
    public int $x = 1;
    public function &get(): int { return $this->x; }
}
function f(): C { return new C; }
f()->get() = 2;
PHP);
    }

    public function testStaticMethodReturnAssignIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("Can't use method return value in write context");
        $this->compileSnippet(<<<'PHP'
class C {
    public static int $x = 1;
    public static function &get(): int { return self::$x; }
}
C::get() = 5;
PHP);
    }

    public function testFunctionReturnAssignIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("Can't use function return value in write context");
        $this->compileSnippet(<<<'PHP'
function &g(): int { static $x = 1; return $x; }
g() = 3;
PHP);
    }

    public function testDimWriteThroughMethodReturnStillCompiles(): void
    {
        $this->compileSnippet(<<<'PHP'
class C {
    public array $a = [1];
    public function &get(): array { return $this->a; }
}
function f(): C { return new C; }
f()->get()[0] = 9;
PHP);
        $this->assertTrue(true);
    }

    public function testMethodReturnIncrementIsCompileFatal(): void
    {
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage("Can't use method return value in write context");
        $this->compileSnippet(<<<'PHP'
class C {
    public int $x = 1;
    public function &get(): int { return $this->x; }
}
(new C)->get()++;
PHP);
    }
}
