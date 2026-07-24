<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #22929 */
final class ClassConstVisibilityInheritCheckTest extends TestCase
{
    public function testPublicToPrivateNarrowingFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public const X = 1; }
class B extends A { private const X = 2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::X must be public (as in class A)');
        $runtime->parseAndCompile($code, 'const_vis_pub_priv.php');
    }

    public function testPublicToProtectedNarrowingFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public const X = 1; }
class B extends A { protected const X = 2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::X must be public (as in class A)');
        $runtime->parseAndCompile($code, 'const_vis_pub_prot.php');
    }

    public function testProtectedToPrivateNarrowingFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { protected const X = 1; }
class B extends A { private const X = 2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::X must be protected (as in class A) or weaker');
        $runtime->parseAndCompile($code, 'const_vis_prot_priv.php');
    }

    public function testProtectedToPublicWideningCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { protected const X = 1; }
class B extends A { public const X = 2; }
echo B::X, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'const_vis_widen.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("2\n", ob_get_clean());
    }

    public function testSamePublicVisibilityCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public const X = 1; }
class B extends A { public const X = 2; }
echo B::X, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'const_vis_same.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("2\n", ob_get_clean());
    }

    public function testPrivateParentAllowsChildPrivateRedeclaration(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { private const X = 1; }
class B extends A { private const X = 2; }
echo "ok\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'const_vis_priv_parent.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("ok\n", ob_get_clean());
    }

    public function testGrandparentPublicCheckedWhenMidDoesNotRedefine(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public const X = 1; }
class Mid extends A {}
class B extends Mid { private const X = 2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::X must be public (as in class A)');
        $runtime->parseAndCompile($code, 'const_vis_grand.php');
    }
}
