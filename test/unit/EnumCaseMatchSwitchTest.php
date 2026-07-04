<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Unqualified enum case labels in match/switch (#6947). */
final class EnumCaseMatchSwitchTest extends TestCase
{
    private const CODE = <<<'PHP'
enum Status { case Pending; case Done; }
$s = Status::Pending;
echo match ($s) {
    Pending => 1,
    Done => 2,
}, "\n";
switch ($s) {
    case Pending:
        echo "done\n";
        break;
}
enum E { case A; case B;
    public function pick(): E {
        return match ($this) {
            A => B,
            B => A,
        };
    }
}
echo E::A->pick()->name, "\n";
PHP;

    public function testVmMatchSwitchUnqualifiedEnumCases(): void
    {
        $rt = new Runtime();
        $block = $rt->parseAndCompile("<?php\n".self::CODE, 'enum_match_switch_unqualified.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\ndone\nB\n", ob_get_clean());
    }

    public function testVmTypedParameterScrutinee(): void
    {
        $code = <<<'PHP'
enum Color { case Red; case Blue; }
function label(Color $c): string {
    return match ($c) {
        Red => 'r',
        Blue => 'b',
    };
}
echo label(Color::Blue);
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile("<?php\n".$code, 'enum_match_typed_param.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('b', ob_get_clean());
    }

    public function testVmBareEnumArmWithEnumCaseScrutineeErrors(): void
    {
        $code = <<<'PHP'
enum E { case A; case B; }
try {
    echo match (E::A) {
        A => 'bare',
    };
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile("<?php\n".$code, 'enum_match_bare_arm.php');
        ob_start();
        $rt->run($block);
        $this->assertSame('Error: Undefined constant "A"', ob_get_clean());
    }
}
