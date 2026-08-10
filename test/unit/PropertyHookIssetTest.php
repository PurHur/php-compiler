<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** isset()/empty() on property hooks — get hook always consulted when present (#29214, #11262, #11617, zend_std_has_property). */
final class PropertyHookIssetTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->skipUnlessPropertyHooksEnabled();
    }


    /** Uninitialized same-name backing still invokes get (Zend fatals inside get on typed uninit). */
    public function testVmIssetOnUninitializedHookedBackingInvokesGet(): void
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
try {
    var_dump(isset($c->x));
} catch (Error $e) {
    echo "err=", $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        self::assertStringContainsString("GET\n", $out);
        self::assertTrue(
            str_contains($out, 'err=') || str_contains($out, 'bool('),
            'isset must reach get hook: '.$out
        );
    }

    public function testVmEmptyOnUninitializedHookedBackingInvokesGet(): void
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
try {
    var_dump(empty($c->x));
} catch (Error $e) {
    echo "err=", $e->getMessage(), "\n";
}
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        $out = (string) ob_get_clean();
        self::assertStringContainsString("GET\n", $out);
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
     * Issue #29214 — non-virtual expression-bodied set keeps backing (isVirtual=false) but
     * isset/empty still invoke get (zend_std_has_property).
     */
    public function testVmIssetEmptyOnExprSetHookInvokesGet(): void
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
        self::assertSame("isset=GET\nY\nempty=GET\nNE\nvirtual=N\n", ob_get_clean());
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
