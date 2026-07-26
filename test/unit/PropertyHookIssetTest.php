<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** isset()/empty() on property hooks — same-name backing probes storage; separate backing invokes get (#11262, #10680, #11467, #11617). */
final class PropertyHookIssetTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }


public function testVmIssetOnUninitializedHookedBackingReturnsFalseWithoutGetHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
    private int $x;
}
$c = new C();
var_dump(isset($c->x));
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("bool(false)\n", ob_get_clean());
    }

    public function testVmEmptyOnUninitializedHookedBackingReturnsTrueWithoutGetHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
    private int $x;
}
$c = new C();
var_dump(empty($c->x));
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("bool(true)\n", ob_get_clean());
    }

    public function testVmIssetOnVirtualGetHookInvokesGet(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public ?string $x {
        get { echo "get runs for isset\n"; return null; }
    }
}
$c = new C();
var_dump(isset($c->x));
echo "ok\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("get runs for isset\nbool(false)\nok\n", ob_get_clean());
    }

    public function testVmIssetOnInitializedSameNameBackingProbesStorageWithoutGetHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
    private int $x = 42;
}
$c = new C();
var_dump(isset($c->x));
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("bool(true)\n", ob_get_clean());
    }

    public function testVmIssetOnSeparateBackingInvokesGetHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $x {
        get { echo "get runs for isset\n"; return $this->backing; }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$c = new C();
var_dump(isset($c->x));
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("get runs for isset\nbool(true)\n", ob_get_clean());
    }

    public function testVmIssetOnSeparateBackingAfterUnsetReturnsFalseWithoutGetHook(): void
    {
        $code = <<<'PHP'
<?php
class RW {
    private ?string $v = 'a';
    public string $x { get => $this->v ?? 'u'; set => $this->v = $value; }
}
$h = new RW();
unset($h->x);
var_dump(isset($h->x));
echo $h->x, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("bool(false)\nu\n", ob_get_clean());
    }

    /** Issue #23339 / re-#17260 — initialized null distinct backing still invokes get for isset/empty. */
    public function testVmIssetEmptyOnNullDistinctBackingInvokesGetHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    private ?string $_n = null;
    public string $name {
        get => $this->_n ?? "anon";
        set(?string $v) => $this->_n = $v;
    }
}
$c = new C();
echo "isset=".(isset($c->name)?"1":"0")."\n";
echo "empty=".(empty($c->name)?"1":"0")."\n";
$c->name = null;
echo "afternull isset=".(isset($c->name)?"1":"0")." empty=".(empty($c->name)?"1":"0")."\n";
unset($c->name);
echo "afterunset isset=".(isset($c->name)?"1":"0")." empty=".(empty($c->name)?"1":"0")."\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame(
            "isset=1\nempty=0\nafternull isset=1 empty=0\nafterunset isset=0 empty=1\n",
            ob_get_clean()
        );
    }
}
