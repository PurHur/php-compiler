<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #26641 */
final class LspSelfResolveCompileCheckTest extends TestCase
{
    public function testStaticToSelfReturnShowsDeclaringClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(): static { return $this; } }
class B extends A { public function f(): self { return $this; } }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Declaration of B::f(): B must be compatible with A::f(): static'
        );
        $runtime->parseAndCompile($code, 'lsp_self_return.php');
    }

    public function testNamespacedSelfResolvesWithNamespace(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
namespace N;
class A { public function f(): static { return $this; } }
class B extends A { public function f(): self { return $this; } }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Declaration of N\\B::f(): N\\B must be compatible with N\\A::f(): static'
        );
        $runtime->parseAndCompile($code, 'lsp_self_ns.php');
    }

    public function testSelfUnionArmResolvesToDeclaringClass(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(): static|int { return $this; } }
class B extends A { public function f(): self|int { return $this; } }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage(
            'Declaration of B::f(): B|int must be compatible with A::f(): static|int'
        );
        $runtime->parseAndCompile($code, 'lsp_self_union.php');
    }

    public function testMatchingSelfReturnStillAllowed(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function f(): self { return $this; } }
class B extends A { public function f(): self { return $this; } }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'lsp_self_ok.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }
}
