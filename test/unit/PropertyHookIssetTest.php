<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** isset()/empty() on property hooks — zend_should_call_hook (#30739, #29214, #11617). */
final class PropertyHookIssetTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->skipUnlessPropertyHooksEnabled();
    }


    /** External isset on uninitialized same-name backing does not invoke get (#30739, #11617). */
    public function testVmIssetOnUninitializedHookedBackingSkipsGet(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $name {
        get { echo "GET\n"; return $this->name; }
        set(string $v) => $this->name = $v;
    }
}
$c = new C();
try {
    var_export(isset($c->name));
    echo "\n";
} catch (Error $e) {
    echo "err=", $e->getMessage(), "\n";
}
$c->name = 'x';
var_export(isset($c->name));
echo "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("false\nGET\ntrue\n", ob_get_clean());
    }

    public function testVmEmptyOnUninitializedHookedBackingSkipsGet(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $name {
        get { echo "GET\n"; return $this->name; }
        set(string $v) => $this->name = $v;
    }
}
$c = new C();
try {
    var_export(empty($c->name));
    echo "\n";
} catch (Error $e) {
    echo "err=", $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("true\n", ob_get_clean());
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

    public function testVmIssetOnInitializedSameNameBackingInvokesGetHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
}
$c = new C();
$c->x = 42;
var_dump(isset($c->x));
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("GET\nbool(true)\n", ob_get_clean());
    }

    /** Inside get: isset($this->prop) on uninitialized same-name backing is false (#29688). */
    public function testVmIssetInsideGetOnUninitializedSameNameBacking(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $prop {
        get => isset($this->prop) ? $this->prop : "missing";
        set => $this->prop = $value;
    }
}
$c = new C;
echo $c->prop, "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("missing\n", ob_get_clean());
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

    /**
     * Issue #29214 / #30739 — short `set =>` is non-virtual (ZEND_ACC_VIRTUAL clear) so
     * uninitialized same-name backing skips get for isset/empty (zend_should_call_hook).
     */
    public function testVmIssetEmptyOnExprSetHookSkipsGetWhenUninitialized(): void
    {
        $code = <<<'PHP'
<?php
class GetSetExpr {
    public string $name {
        get => (function () { echo "GET\n"; return 'x'; })();
        set => throw new Error('no');
    }
}
$o = new GetSetExpr;
echo 'isset=', isset($o->name) ? 'Y' : 'N', "\n";
echo 'empty=', empty($o->name) ? 'E' : 'NE', "\n";
$rp = new ReflectionProperty(GetSetExpr::class, 'name');
echo 'virtual=', $rp->isVirtual() ? 'Y' : 'N', "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'issue29214.php');
        ob_start();
        $rt->run($block);
        self::assertSame("isset=N\nempty=E\nvirtual=N\n", ob_get_clean());
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
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame(
            "isset=1\nempty=0\nafternull isset=1 empty=0\n",
            ob_get_clean()
        );
    }
}
