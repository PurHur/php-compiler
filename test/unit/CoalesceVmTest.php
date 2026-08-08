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

    /** Issue #4416: RHS side effects only when LHS is null (zend_compile.c ??=). */
    public function testNullCoalesceAssignRhsSideEffects(): void
    {
        $this->assertVmOutput(
            '<?php
$side = 0;
function rhs() { global $side; $side++; return 123; }

$a = null;
$a ??= rhs();
echo $a, ",", $side, "\n";

$b = 5;
$b ??= rhs();
echo $b, ",", $side, "\n";

$arr = ["k" => null];
$arr["k"] ??= rhs();
echo $arr["k"], ",", $side, "\n";

$arr2 = ["k" => 9];
$arr2["k"] ??= rhs();
echo $arr2["k"], ",", $side, "\n";
',
            "123,1\n5,1\n123,2\n9,2\n"
        );
    }

    /** Issue #4416: unset offset ??= must not eager-fetch before isset (no undefined-key warning). */
    public function testNullCoalesceAssignUnsetKeyFunctionRhs(): void
    {
        $this->assertVmOutput(
            '<?php
function rhs() { return 42; }
$u = [];
$u["k"] ??= rhs();
echo $u["k"], "\n";
',
            "42\n"
        );
    }

    /** Issue #21992: ??= / dim-write on undefined or null container auto-vivifies (zend_execute.c). */
    public function testNullCoalesceAssignDimAutovivifyUndefAndNull(): void
    {
        $this->assertVmOutput(
            '<?php
$b["k"] ??= "y";
echo $b["k"], "\n";
$c = null;
$c["k"] ??= "y";
var_export($c);
echo "\n";
$d["k"] = "z";
echo $d["k"], "\n";
$e = null;
$e["k"] = "z";
var_export($e);
echo "\n";
try {
    $i = 0;
    $i["k"] ??= "no";
    echo "scalar-ok\n";
} catch (Error $err) {
    echo get_class($err), ": ", $err->getMessage(), "\n";
}
',
            "y\narray (\n  'k' => 'y',\n)\nz\narray (\n  'k' => 'z',\n)\nError: Cannot use a scalar value as an array\n"
        );
    }

    /**
     * Issue #29146: FETCH_DIM_W on undefined script CV must mark the global assigned so a later
     * bare read (var_export($a)) does not emit Undefined variable (Zend/zend_execute.c).
     */
    public function testNullCoalesceAssignDimUndefVarQuietOnBareRead(): void
    {
        $code = file_get_contents(__DIR__ . '/../repro/issue_29146_coalesce_dim_undef_var_quiet.php');
        $this->assertNotFalse($code);
        $this->assertVmOutput(
            $code,
            "array (\n  'x' => 1,\n)\narray (\n  'k' => 'y',\n)\n"
        );

        $repoRoot = dirname(__DIR__, 2);
        $cmd = [
            PHP_BINARY,
            '-d', 'display_errors=1',
            '-d', 'error_reporting=E_ALL',
            $repoRoot.'/bin/vm.php',
            $repoRoot.'/test/repro/issue_29146_coalesce_dim_undef_var_quiet.php',
        ];
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptors, $pipes, $repoRoot);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        $this->assertSame(0, $exit, $stderr);
        $this->assertSame("array (\n  'x' => 1,\n)\narray (\n  'k' => 'y',\n)\n", $stdout);
        $this->assertStringNotContainsString('Undefined variable', (string) $stderr);
    }

    /** Issue #28954: nested dim ??= auto-vivifies intermediates without Undefined array key. */
    public function testNullCoalesceAssignNestedDimAutovivify(): void
    {
        $this->assertVmOutput(
            '<?php
$a = [];
$r = ($a["x"]["y"] ??= 1);
echo $r, "\n";
var_export($a);
echo "\n";
$r2 = ($a["x"]["y"] ??= 2);
echo $r2, "\n";
$b = [];
$r3 = ($b["x"]["y"]["z"] ??= 3);
echo $r3, "\n";
var_export($b);
echo "\n";
$d = [];
echo ($d["x"]["y"] ?? 5), "\n";
var_export($d);
echo "\n";
',
            "1\narray (\n  'x' => array (\n    'y' => 1,\n  ),\n)\n1\n3\narray (\n  'x' => array (\n    'y' => array (\n      'z' => 3,\n    ),\n  ),\n)\n5\narray (\n)\n"
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

    /** Issue #5337: ??= expression value must match assigned value (zend_compile.c). */
    public function testNullCoalesceAssignExpressionValue(): void
    {
        $this->assertVmOutput(
            '<?php
$a = null;
ob_start();
var_dump($a ??= 5, $a);
$out = ob_get_clean();
echo $out;

$b = null;
$b = $b ??= 7;
echo $b, "\n";

$x = null;
$y = null;
ob_start();
var_dump($x ??= $y ??= 1, $x, $y);
$chain = ob_get_clean();
echo $chain;
',
            "int(5)
int(5)
7
int(1)
int(1)
int(1)
"
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

    /** Issue #15315: throw expr as ?? LHS — php-cfg emits Coalesce then Throw; must not double-lower. */
    public function testThrowExpressionNullCoalesceLeftOperand(): void
    {
        $this->assertVmOutput(
            '<?php
class Ex extends Exception {}
try {
    $x = throw new Ex("coalesce") ?? 1;
    echo "fail\n";
} catch (Ex $e) {
    echo "ok\n";
}
try {
    $y = (throw new Ex("nested") ?? 2) ?? 3;
    echo "fail\n";
} catch (Ex $e) {
    echo "ok\n";
}
',
            "ok\nok\n"
        );
    }

    /** Issue #9479: null container dim ?? default in func-call arg must not leave dead temp (#10390 audit). */
    public function testNullCoalesceNullOffsetFuncCallArg(): void
    {
        $this->assertVmOutput(
            '<?php
$x = null;
var_export($x["message"] ?? "no warning");
echo "\n";
',
            "'no warning'\n"
        );
    }

    /** Issue #10743: chained dim-fetch ?? in func-call arg must read coalesce merge slot, not inner fetch temp. */
    public function testChainedArrayDimCoalesceFuncCallArg(): void
    {
        $this->assertVmOutput(
            '<?php
date_create_from_format("!Y-m-d", "2024-02-30");
$errs = DateTime::getLastErrors();
var_export($errs["warnings"][10] ?? null);
echo "\n";
',
            "'The parsed date was invalid'\n"
        );
    }

    /** Issue #11601: stmt ?? before var_export(..., true) after prior call must wire coalesce slot (WeakMap repro). */
    public function testCoalesceFuncCallArgAfterPriorVarExport(): void
    {
        $this->assertVmOutput(
            '<?php
declare(strict_types=1);
$wm = new WeakMap();
$obj = new stdClass();
$wm[$obj] = "val";
echo "direct=", var_export($wm[$obj], true), "\n";
echo "nullco=", var_export($wm[$obj] ?? null, true), "\n";
',
            "direct='val'\nnullco='val'\n"
        );
    }

    public function testArrayDimCoalesceFuncCallArgWithTrueSecondArg(): void
    {
        $this->assertVmOutput(
            '<?php
$a = ["k" => "val"];
echo "x=", var_export($a["k"], true), "\n";
echo "y=", var_export($a["k"] ?? null, true), "\n";
',
            "x='val'\ny='val'\n"
        );
    }

    /** Issue #15946 — outer call must use inner callee result, not ?? slot (array_keys after dim ??). */
    public function testArrayDimCoalesceNestedFuncCallArg(): void
    {
        $this->assertVmOutput(
            '<?php
declare(strict_types=1);
$a = ["k" => ["x" => 1]];
var_dump(array_keys($a["k"] ?? []));
',
            "array(1) {\n  [0]=>\n  string(1) \"x\"\n}\n"
        );
    }

    /** Issue #16127 — inline dim-fetch ?? as call arg (regression from #16125 branch rewire). */
    public function testArrayDimCoalesceInlineFuncCallArgWithoutAssign(): void
    {
        $this->assertVmOutput(
            file_get_contents(__DIR__ . '/../repro/maintainer_gap_ini_get_all_array_keys_inline.php'),
            "array(3) {\n  [0]=>\n  string(12) \"global_value\"\n  [1]=>\n  string(11) \"local_value\"\n  [2]=>\n  string(6) \"access\"\n}\n"
            . "array(1) {\n  [0]=>\n  string(1) \"x\"\n}\n"
        );
    }

    /** Issue #10743 — nested dim-fetch ?? before func call must pass coalesce result (#15945). */
    public function testNestedDimFetchCoalesceFuncCallArg(): void
    {
        $this->assertVmOutput(
            '<?php
declare(strict_types=1);
$root = ["warnings" => [10 => "The parsed date was invalid"]];
var_export($root["warnings"][10] ?? null);
echo "\n";
',
            "'The parsed date was invalid'\n"
        );
    }

    /** Issue #15946 — ini_get_all details nested under array_keys(??). */
    public function testIniGetAllDetailsCoalesceInArrayKeys(): void
    {
        $this->assertVmOutput(
            '<?php
declare(strict_types=1);

$all = ini_get_all(null, true);
var_dump(array_keys($all[\'display_errors\'] ?? []));
$flat = ini_get_all(null, false);
echo is_string($flat[\'display_errors\'] ?? null) ? "flat string\n" : "flat not string\n";
',
            "array(3) {\n  [0]=>\n  string(12) \"global_value\"\n  [1]=>\n  string(11) \"local_value\"\n  [2]=>\n  string(6) \"access\"\n}\nflat string\n"
        );
    }

    /** Issue #11801: ?? binds below additive/concat; deferred RHS must run on the null branch. */
    public function testNullCoalesceOperatorPrecedence(): void
    {
        $this->assertVmOutput(
            file_get_contents(__DIR__ . '/../repro/maintainer_gap_null_coalesce_precedence.php'),
            "ok null ?? 1 + 2\n"
            . "ok unset coalesce add\n"
            . "ok dim coalesce add\n"
            . "ok null ?? concat\n"
            . "ok nullsafe coalesce add\n"
            . "ok chained coalesce add\n"
        );
    }

    /** Issue #13105: ?: binds below additive/concat; deferred RHS must run on the falsy branch. */
    public function testElvisOperatorPrecedence(): void
    {
        $this->assertVmOutput(
            file_get_contents(__DIR__ . '/../repro/maintainer_gap_elvis_precedence.php'),
            "ok null ?: 1 + 2\n"
            . "ok 0 ?: 1 + 2\n"
            . "ok false ?: 1 + 2\n"
            . "ok empty string ?: concat\n"
            . "ok var 0 ?: 1 + 2\n"
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
