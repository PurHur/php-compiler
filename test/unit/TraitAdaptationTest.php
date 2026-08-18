<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Trait use adaptations: `as` alias and `insteadof` precedence (#3238). */
final class TraitAdaptationTest extends TestCase
{
    public function testTraitMethodAliasAsKeepsOriginal(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function f(): int { return 1; } public function g(): int { return 2; } }
class C { use T { f as renamed; } }
$c = new C();
echo (int) method_exists($c, 'f'), (int) method_exists($c, 'renamed'), $c->f(), $c->renamed(), $c->g();
PHP;
        $this->assertSame('11112', $this->runVm($code));
    }

    public function testTraitMethodAsVisibilityPlusAliasKeepsOriginal(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function foo() { return 1; } }
class C {
    use T { foo as protected foo2; }
    public function call() { return $this->foo() + $this->foo2(); }
}
$c = new C();
echo $c->call(), (int) method_exists($c, 'foo'), (int) method_exists($c, 'foo2');
PHP;
        $this->assertSame('211', $this->runVm($code));
    }

    public function testTraitInsteadofResolvesConflict(): void
    {
        $code = <<<'PHP'
<?php
trait T1 { public function f(): int { return 1; } }
trait T2 { public function f(): int { return 99; } public function g(): int { return 2; } }
class C { use T1, T2 { T1::f insteadof T2; } }
$c = new C();
echo $c->f(), $c->g();
PHP;
        $this->assertSame('12', $this->runVm($code));
    }

    public function testTraitInsteadofWithTraitQualifiedAlias(): void
    {
        $code = <<<'PHP'
<?php
trait TA { public function f(): string { return 'A'; } }
trait TB { public function f(): string { return 'B'; } }
final class C {
    use TA, TB {
        TA::f insteadof TB;
        TB::f as g;
    }
}
$c = new C();
echo $c->f(), $c->g();
PHP;
        $this->assertSame('AB', $this->runVm($code));
    }

    public function testTraitConflictWithoutAdaptationIsFatal(): void
    {
        $code = <<<'PHP'
<?php
trait T1 { public function f(): int { return 1; } }
trait T2 { public function f(): int { return 2; } }
class C { use T1, T2; }
new C();
PHP;
        $rt = new Runtime();
        $this->expectException(\CompileError::class);
        $this->expectExceptionMessage('collision');
        $rt->parseAndCompile($code, 'trait_adapt.php');
    }

    public function testTraitMethodAsPrivateVisibility(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function f(): int { return 1; } }
class C {
    use T { f as private; }
    public function call(): int { return $this->f(); }
}
$c = new C();
echo $c->call();
PHP;
        $this->assertSame('1', $this->runVm($code));
    }

    /** Visibility adaptation + same-name class method — Zend composes then overrides (#25577). */
    public function testTraitAsPrivateWithClassMethodOverride(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function f() { return 'T'; } public function g() { return 'G'; } }
class C {
    use T { g as private; }
    public function g() { return 'C'; }
}
$c = new C();
echo $c->f(), ',', $c->g();
PHP;
        $this->assertSame('T,C', $this->runVm($code));
    }

    public function testTraitMethodAsPrivateBlocksExternalCall(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function f(): int { return 1; } }
class C { use T { f as private; } }
$c = new C();
$c->f();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_vis.php');
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Call to private method');
        $rt->run($block);
    }

    public function testTraitMethodAsPrivateWithRename(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function f(): int { return 1; } }
class C {
    use T { f as private renamed; }
    public function call(): int { return $this->renamed(); }
}
$c = new C();
echo $c->call();
PHP;
        $this->assertSame('1', $this->runVm($code));
    }

    public function testTraitMethodAsProtectedVisibility(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function f(): int { return 1; } }
class C {
    use T { f as protected; }
    public function call(): int { return $this->f(); }
}
$c = new C();
echo $c->call();
PHP;
        $this->assertSame('1', $this->runVm($code));
    }

    /** Nested trait named in insteadof must be a direct use (zend_check_trait_usage, #32130). */
    public function testDiamondInsteadofRequiresDirectUse(): void
    {
        $code = <<<'PHP'
<?php
trait DA {}
trait DB { use DA; }
trait DC { use DA; }
class DD {
    use DB, DC {
        DA::m insteadof DB, DC;
    }
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_diamond_insteadof.php');
        try {
            $rt->run($block);
            $this->fail('expected required-trait fatal');
        } catch (\Throwable $e) {
            $this->assertStringContainsString("Required Trait DA wasn't added", $e->getMessage());
            $this->assertStringContainsString("wasn't added to DD", $e->getMessage());
            $this->assertStringNotContainsString('Could not find trait DA', $e->getMessage());
        }
    }

    /** Existing unused insteadof loser uses the same required-trait wording (#32130). */
    public function testInsteadofUnusedLoserTraitRequiresDirectUse(): void
    {
        $code = <<<'PHP'
<?php
trait T1 { public function f() {} }
trait T2 { public function f() {} }
trait T3 { public function f() {} }
class C {
    use T1, T2 { T1::f insteadof T3; }
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_insteadof_unused_loser.php');
        try {
            $rt->run($block);
            $this->fail('expected required-trait fatal');
        } catch (\Throwable $e) {
            $this->assertStringContainsString("Required Trait T3 wasn't added to C", $e->getMessage());
            $this->assertStringNotContainsString('Could not find trait T3', $e->getMessage());
        }
    }

    /** Unknown insteadof name stays "Could not find trait" (zend_traits_init_trait_structures). */
    public function testInsteadofUnknownTraitStillCouldNotFind(): void
    {
        $code = <<<'PHP'
<?php
trait T1 { public function f() {} }
trait T2 { public function f() {} }
class C {
    use T1, T2 { T1::f insteadof T3; }
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_insteadof_unknown.php');
        try {
            $rt->run($block);
            $this->fail('expected could-not-find-trait fatal');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Could not find trait T3', $e->getMessage());
            $this->assertStringNotContainsString("Required Trait T3", $e->getMessage());
        }
    }

    /** Alias naming a nested unused trait uses required-not-added (#32130). */
    public function testAliasUnusedExistingTraitRequiresDirectUse(): void
    {
        $code = <<<'PHP'
<?php
trait DA { public function m() { return 1; } }
trait DB { use DA; }
class DD {
    use DB { DA::m as mm; }
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_alias_unused.php');
        try {
            $rt->run($block);
            $this->fail('expected required-trait fatal');
        } catch (\Throwable $e) {
            $this->assertStringContainsString("Required Trait DA wasn't added to DD", $e->getMessage());
            $this->assertStringNotContainsString('Could not find trait DA', $e->getMessage());
        }
    }

    /** Alias onto an existing composed name uses Zend collision wording (#25080). */
    public function testTraitAsAliasOntoExistingNameIsCollisionFatal(): void
    {
        $code = <<<'PHP'
<?php
trait T {
    public function f() { return 1; }
    public function g() { return 2; }
}
class A {
    use T {
        f as private hid;
        g as f;
    }
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_alias_collision.php');
        try {
            $rt->run($block);
            $this->fail('expected trait alias collision fatal');
        } catch (\Throwable $e) {
            $this->assertStringContainsString(
                'Trait method T::g has not been applied as A::f, because of collision with T::f',
                $e->getMessage()
            );
            $this->assertStringNotContainsString('Cannot redefine method', $e->getMessage());
        }
    }

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_adapt.php');
        ob_start();
        $rt->run($block);

        return ob_get_clean();
    }
}
