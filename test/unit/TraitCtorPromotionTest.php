<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Constructor property promotion merged from traits (#4671). */
final class TraitCtorPromotionTest extends TestCase
{
    public function testTraitPromotedPropertyReadableAfterConstruct(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait HasX {
    public function __construct(public int $x) {}
}
class C {
    use HasX;
}
$c = new C(3);
echo $c->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'trait_ctor_promotion.php'));
        $this->assertSame("3\n", ob_get_clean());
    }

    public function testDuplicateTraitConstructorsFailAtCompileTime(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T1 { public function __construct(public int $x) {} }
trait T2 { public function __construct(public int $x) {} }
class C { use T1, T2; }
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Trait method T2::__construct has not been applied');
        $this->expectExceptionMessage('collision with T1::__construct');
        $runtime->parseAndCompile($code, 'trait_promotion_collision.php');
    }

    public function testClassPropertyCollidesWithTraitPromotion(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
trait T { public function __construct(public int $x) {} }
class C {
    public int $x = 0;
    use T;
}
PHP;
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('Cannot redeclare C::$x');
        $runtime->parseAndCompile($code, 'trait_promotion_class_collision.php');
    }
}
