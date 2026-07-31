<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #25661 */
final class PropertyVisibilityInheritCheckTest extends TestCase
{
    public function testPublicToProtectedInstanceNarrowingFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public $x = 1; }
class B extends A { protected $x = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::$x must be public (as in class A)');
        $runtime->parseAndCompile($code, 'prop_vis_pub_prot.php');
    }

    public function testPublicToProtectedStaticNarrowingFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public static $x = 1; }
class B extends A { protected static $x = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::$x must be public (as in class A)');
        $runtime->parseAndCompile($code, 'prop_vis_static_pub_prot.php');
    }

    public function testProtectedToPrivateNarrowingFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { protected $x = 1; }
class B extends A { private $x = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::$x must be protected (as in class A) or weaker');
        $runtime->parseAndCompile($code, 'prop_vis_prot_priv.php');
    }

    public function testProtectedToPublicWideningCompilesAndRuns(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { protected $x = 1; }
class B extends A { public $x = 2; }
$b = new B();
echo $b->x, "\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'prop_vis_widen.php');
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
class A { public $x = 1; }
class B extends A { public $x = 2; }
echo "LOADED\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'prop_vis_same.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("LOADED\n", ob_get_clean());
    }

    public function testPrivateParentAllowsChildRedeclaration(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { private $x = 1; }
class B extends A { public $x = 2; }
echo "LOADED\n";
PHP;
        $block = $runtime->parseAndCompile($code, 'prop_vis_priv_parent.php');
        $this->assertNotNull($block);
        ob_start();
        $runtime->run($block);
        $this->assertSame("LOADED\n", ob_get_clean());
    }

    public function testGrandparentPublicCheckedWhenMidDoesNotRedefine(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public $x = 1; }
class Mid extends A {}
class B extends Mid { protected $x = 1; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::$x must be public (as in class A)');
        $runtime->parseAndCompile($code, 'prop_vis_grand.php');
    }

    public function testPromotedPropertyNarrowingFailsCompile(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class A { public function __construct(public $x) {} }
class B extends A { public function __construct(protected $x) { parent::__construct($x); } }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Access level to B::$x must be public (as in class A)');
        $runtime->parseAndCompile($code, 'prop_vis_promoted.php');
    }
}
