<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PropertyVisibility;
use PHPCompiler\Runtime;
use PHPCfg\Func as CfgFunc;
use PHPUnit\Framework\TestCase;

/** PHP 8.4+ implicit protected(set) on public readonly + clone-with guard (#29186). */
final class CloneWithReadonlyReinitTest extends TestCase
{
    public function testImplicitProtectedSetForPublicReadonlyUnder84(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.4');
        try {
            $this->assertTrue(CompilerVersion::supportsAsymmetricVisibility());
            $this->assertSame(
                CfgFunc::FLAG_PROTECTED,
                PropertyVisibility::withImplicitReadonlyProtectedSet(
                    true,
                    CfgFunc::FLAG_PUBLIC,
                    0
                )
            );
            $this->assertSame(
                0,
                PropertyVisibility::withImplicitReadonlyProtectedSet(
                    true,
                    CfgFunc::FLAG_PROTECTED,
                    0
                )
            );
            $this->assertSame(
                CfgFunc::FLAG_PUBLIC,
                PropertyVisibility::withImplicitReadonlyProtectedSet(
                    true,
                    CfgFunc::FLAG_PUBLIC,
                    CfgFunc::FLAG_PUBLIC
                )
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testCloneWithReadonlyFromGlobalMatchesZendMessage(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            if (!CompilerVersion::supportsCloneWithSyntax()) {
                $this->markTestSkipped('clone-with requires PROFILE≥8.5');
            }
            $code = <<<'PHP'
<?php
class C {
  public function __construct(public readonly int $x, public readonly int $y) {}
}
$o = new C(1, 2);
try {
  $n = clone($o, ['x' => 9]);
  echo 'OK:', $n->x, '|', $n->y;
} catch (Throwable $e) {
  echo get_class($e), ':', $e->getMessage();
}
echo "\n";
readonly class R {
  public function __construct(public int $a) {}
}
$r = new R(1);
try {
  $n = clone($r, ['a' => 7]);
  echo 'OKR:', $n->a;
} catch (Throwable $e) {
  echo get_class($e), ':', $e->getMessage();
}
echo "\n";
PHP;
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'clone_with_readonly_reinit.php');
            ob_start();
            $rt->run($block);
            $out = (string) ob_get_clean();
            $this->assertSame(
                "Error:Cannot modify protected(set) readonly property C::\$x from global scope\n"
                ."Error:Cannot modify protected(set) readonly property R::\$a from global scope\n",
                $out
            );
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testCloneWithReadonlyFromClassScopeAllowed(): void
    {
        $prev = getenv('PHP_COMPILER_PROFILE');
        putenv('PHP_COMPILER_PROFILE=8.5');
        try {
            if (!CompilerVersion::supportsCloneWithSyntax()) {
                $this->markTestSkipped('clone-with requires PROFILE≥8.5');
            }
            $code = <<<'PHP'
<?php
class C {
  public function __construct(public readonly int $x, public readonly int $y) {}
  public function withX(): self { return clone($this, ['x' => 9]); }
}
$n = (new C(1, 2))->withX();
echo 'OK:', $n->x, '|', $n->y, "\n";
PHP;
            $rt = new Runtime();
            $block = $rt->parseAndCompile($code, 'clone_with_readonly_wither.php');
            ob_start();
            $rt->run($block);
            $this->assertSame("OK:9|2\n", (string) ob_get_clean());
        } finally {
            if (false === $prev) {
                putenv('PHP_COMPILER_PROFILE');
            } else {
                putenv('PHP_COMPILER_PROFILE='.$prev);
            }
        }
    }

    public function testProtectedSetReadonlyMessageWording(): void
    {
        try {
            PropertyVisibility::assertWritable(
                CfgFunc::FLAG_PROTECTED,
                null,
                'c',
                'C',
                'x',
                static fn (): bool => false,
                MethodVisibility::mask(CfgFunc::FLAG_PUBLIC),
                false,
                null,
                true
            );
            $this->fail('expected LogicException');
        } catch (\LogicException $e) {
            $this->assertSame(
                'Cannot modify protected(set) readonly property C::$x from global scope',
                $e->getMessage()
            );
        }
    }
}
