<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Trait use adaptations: `as` alias and `insteadof` precedence (#3238). */
final class TraitAdaptationTest extends TestCase
{
    public function testTraitMethodAliasAsRename(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function f(): int { return 1; } public function g(): int { return 2; } }
class C { use T { f as renamed; } }
$c = new C();
echo $c->renamed(), $c->g();
PHP;
        $this->assertSame('12', $this->runVm($code));
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

    private function runVm(string $code): string
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'trait_adapt.php');
        ob_start();
        $rt->run($block);

        return ob_get_clean();
    }
}
