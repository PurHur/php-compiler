<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** Unqualified enum case labels in match/switch (#6947, #16720). */
final class EnumCaseMatchSwitchTest extends TestCase
{
    public function testVmMatchSwitchUnqualifiedEnumCasesError(): void
    {
        $code = <<<'PHP'
enum Status { case Pending; case Done; }
$s = Status::Pending;
try {
    echo match ($s) {
        Pending => 1,
        Done => 2,
    }, "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    switch ($s) {
        case Pending:
            echo "done\n";
            break;
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo match ($s) {
    Status::Pending => 1,
    Status::Done => 2,
}, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile("<?php\n".$code, 'enum_match_switch_unqualified.php');
        ob_start();
        $rt->run($block);
        $this->assertSame(
            "Error: Undefined constant \"Pending\"\nError: Undefined constant \"Pending\"\n1\n",
            ob_get_clean()
        );
    }

    public function testVmTypedParameterScrutineeRequiresQualifiedCases(): void
    {
        $code = <<<'PHP'
enum Color { case Red; case Blue; }
function label(Color $c): string {
    return match ($c) {
        Color::Red => 'r',
        Color::Blue => 'b',
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
