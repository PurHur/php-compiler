<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** ?? on property hooks invokes get when present (#29266, zend_object_handlers.c). */
final class PropertyHookCoalesceTest extends TestCase
{
    use PropertyHookTestSkip;

    protected function setUp(): void
    {
        putenv('PHP_COMPILER_PROFILE=8.4');
        $_ENV['PHP_COMPILER_PROFILE'] = '8.4';
        $this->skipUnlessPropertyHooksEnabled();
    }

    public function testVmCoalesceInvokesGetOnSeparateBacking(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $x {
        get { echo "GET\n"; return $this->backing; }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$c = new C();
var_dump($c->x ?? 'default');
echo "ok\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("GET\nstring(1) \"a\"\nok\n", ob_get_clean());
    }

    public function testVmCoalesceVirtualGetOnly(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $x {
        get => 'hello';
    }
}
$o = new C;
echo ($o->x ?? 'rhs'), "\n";
class D {
    public ?string $y {
        get => null;
    }
}
$d = new D;
echo ($d->y ?? 'rhs'), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("hello\nrhs\n", ob_get_clean());
    }

    /** External ?? on uninitialized same-name backing skips get (#30739). */
    public function testVmCoalesceOnUninitializedSameNameBackingSkipsGet(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $name {
        get { echo "GET\n"; return $this->name; }
        set(string $v) => $this->name = $v;
    }
}
$c = new C;
try {
    echo ($c->name ?? 'd'), "\n";
} catch (Error $e) {
    echo "err\n";
}
$c->name = 'x';
echo ($c->name ?? 'd'), "\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("d\nGET\nx\n", ob_get_clean());
    }
}
