<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** Issue #99 / #1960: null coalescing (??) and ??= VM compliance slice for ci-fast. */
final class CoalesceVmTest extends TestCase
{
    public function testNullCoalesceOperator(): void
    {
        $this->assertVmOutput(
            '<?php
$b = null;
echo $b ?? "Guest", "\n";

$c = "Alice";
echo $c ?? "Guest", "\n";

$items = ["page" => "home"];
echo $items["page"] ?? "index", "\n";
echo $items["missing"] ?? "index", "\n";

echo 0 ?? "zero", "\n";
echo "" ?? "empty", "\n";

function returnsNull() {
    return null;
}
echo returnsNull() ?? "fallback", "\n";

echo $_GET["missing"] ?? "from-get", "\n";
',
            "Guest\nAlice\nhome\nindex\n0\n\nfallback\nfrom-get\n"
        );
    }

    public function testNullCoalesceAssign(): void
    {
        $this->assertVmOutput(
            '<?php
$a = null;
$a ??= "default";
echo $a, "\n";

$b = "set";
$b ??= "ignored";
echo $b, "\n";

$items = [];
$items["page"] ??= "home";
echo $items["page"], "\n";

$items["page"] ??= "other";
echo $items["page"], "\n";

$_GET["missing"] ??= "from-get";
echo $_GET["missing"], "\n";
',
            "default\nset\nhome\nhome\nfrom-get\n"
        );
    }

    public function testNullCoalesceAssignEchoInline(): void
    {
        $this->assertVmOutput(
            '<?php
echo $_GET["k"] ??= "default";
echo "\n";

$a = null;
echo $a ??= "default";
echo "\n";

$items = [];
echo $items["page"] ??= "home";
echo "\n";
',
            "default\ndefault\nhome\n"
        );
    }

    /** Issue #3462: ($x ?? throw new Ex()) — RHS only when LHS is null. */
    public function testNullCoalesceNestedInCast(): void
    {
        $this->assertVmOutput(
            '<?php
$stat = ["mode" => 0644];
echo (int) ($stat["mode"] ?? 0), "\n";
echo (int) ($stat["missing"] ?? 0), "\n";
',
            "420\n0\n"
        );
    }

    /** Issue #3798: chained ?? short-circuits left-to-right (Zend zend_compile.c). */
    public function testNullCoalesceChain(): void
    {
        $this->assertVmOutput(
            '<?php
$a = null;
$b = null;
echo $a ?? $b ?? "z", "\n";

$c = 0;
echo $c ?? "zero", "\n";
echo null ?? "n", "\n";
',
            "z\n0\nn\n"
        );
    }

    public function testNullCoalesceThrow(): void
    {
        $this->assertVmOutput(
            '<?php
class Ex {
    public string $m;
    public function __construct(string $m) { $this->m = $m; }
}
try {
    echo ($missing ?? throw new Ex("missing")), "\n";
} catch (Ex $e) {
    echo "caught:", $e->m, "\n";
}
$ok = 1;
$hit = 0;
try {
    echo ($ok ?? throw new Ex("no")), "\n";
} catch (Ex $e) {
    $hit = 1;
}
echo $hit, "\n";
',
            "caught:missing\n1\n0\n"
        );
    }

    private function assertVmOutput(string $code, string $expected): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile($code, 'test.php');
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
