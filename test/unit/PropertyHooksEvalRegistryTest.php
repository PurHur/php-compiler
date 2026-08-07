<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** @covers issue #7031 */
final class PropertyHooksEvalRegistryTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }


public function testEvalConcretePropertyHookMergesSameNameBackingField(): void
    {
        $runtime = new Runtime();
        $code = <<<'EVAL'
class Evaled {
    public string $name {
        get => strtoupper($this->name ?? "");
        set => $this->name = strtolower($value);
    }
    private string $name = "x";
}
EVAL;
        $wrapped = VmEval::wrapEvalCode($code);
        [$processed] = $runtime->preprocessSourceForParse($wrapped, VmEval::EVAL_FILENAME);
        self::assertStringContainsString('public string $name = "x";', $processed);
        self::assertStringNotContainsString('private string $name', $processed);
        self::assertStringContainsString('function __phpc_property_get_name', $processed);
    }

    public function testEvalPropertyHookRegistryMergesAcrossCompileUnits(): void
    {
        $runtime = new Runtime();
        $first = <<<'PHP'
<?php
class FirstHooked {
    public string $label {
        get => $this->label;
        set => $this->label = $value;
    }
    private string $label = 'a';
}
PHP;
        $runtime->preprocessSourceForParse($first, 'first.php');
        self::assertArrayHasKey('firsthooked', $runtime->vmContext->propertyHookRegistry);

        $evalCode = 'class EvalHooked { public int $n { get => $this->n; set => $this->n = $value; } private int $n = 0; }';
        $runtime->preprocessSourceForParse(VmEval::wrapEvalCode($evalCode), VmEval::EVAL_FILENAME);
        self::assertArrayHasKey('firsthooked', $runtime->vmContext->propertyHookRegistry);
        self::assertArrayHasKey('evalhooked', $runtime->vmContext->propertyHookRegistry);
    }

    public function testFileCompileUnitsMergePropertyHookRegistryAcrossRequires(): void
    {
        $runtime = new Runtime();
        $first = <<<'PHP'
<?php
interface FirstIface {
    public string $label { get; set; }
}
PHP;
        $runtime->preprocessSourceForParse($first, 'first_iface.php');
        self::assertArrayHasKey('firstiface', $runtime->vmContext->propertyHookRegistry);

        $second = <<<'PHP'
<?php
class SecondPlain {}
PHP;
        $runtime->preprocessSourceForParse($second, 'second_plain.php');
        self::assertArrayHasKey('firstiface', $runtime->vmContext->propertyHookRegistry);
    }

    public function testEvalConcretePropertyHookRuns(): void
    {
        $runtime = new Runtime();
        $outer = <<<'PHP'
<?php
$ok = eval('class Evaled {
    public string $name {
        get => strtoupper($this->name ?? "");
        set => $this->name = strtolower($value);
    }
    private string $name = "x";
}');
if ($ok === false) {
    echo "eval-failed\n";
    return;
}
$o = new Evaled();
$o->name = 'AbC';
echo $o->name, "\n";
PHP;
        $block = $runtime->parseAndCompile($outer, 'eval_concrete_hook.php');
        self::assertNotNull($block);
        ob_start();
        $runtime->run($block);
        self::assertSame("ABC\n", ob_get_clean());
    }
}
