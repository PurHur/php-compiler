<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Lint\UnsupportedRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Epic #2483 done-when guard: wave-4 language subset (closures, inheritance, traits, generators).
 *
 * php-src refs: zend_closures.c, zend_inheritance.c, zend_traits.c, zend_generators.c
 */
final class Wave4EpicVerificationTest extends TestCase
{
    public function testWave4ConstructsNotLintTracked(): void
    {
        foreach ([
            'Expr_Closure',
            'Expr_ArrowFunction',
            'Expr_Yield',
            'Expr_YieldFrom',
            'Stmt_TraitUse',
            'Stmt_TraitUseAdaptation',
        ] as $kind) {
            $this->assertNull(
                UnsupportedRegistry::trackingIssueForKind($kind),
                $kind.' should compile, not lint as unsupported (#2483)'
            );
        }
    }

    public function testWave4SubsetVmRepro(): void
    {
        $this->assertVmOutput(
            <<<'PHP'
<?php
trait T {
    public function f(): int { return 1; }
}
class Base {
    public function id(): string { return 'base'; }
}
class Child extends Base {
    use T {
        T::f as tf;
    }
    public function id(): string { return 'child'; }
}
$n = 0;
$inc = function () use (&$n): int {
    return ++$n;
};
$double = fn (int $x): int => $x * 2;
echo $inc(), "\n";
echo $double(3), "\n";
echo (new Child())->tf(), "\n";
echo (new Child())->id(), "\n";
$gen = function (): Generator {
    yield 10;
    yield 20;
};
foreach ($gen() as $v) {
    echo $v, "\n";
}
PHP,
            "1\n6\n1\nchild\n10\n20\n"
        );
    }

    private function assertVmOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'wave4_epic.php');
        $this->assertNotNull($block);
        ob_start();
        try {
            $runtime->run($block);
        } catch (VM\ScriptExit $e) {
            // exit() in compiled code
        }
        $actual = ob_get_clean();
        $this->assertSame($expected, $actual, 'VM stdout');
    }
}
