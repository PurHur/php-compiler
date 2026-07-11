<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\Test\Support\PropertyHookTestSkip;
use PHPUnit\Framework\TestCase;

/** ?? on property hooks must not invoke get hook (#8919, #8902). */
final class PropertyHookCoalesceTest extends TestCase
{
        use PropertyHookTestSkip;

    protected function setUp(): void
    {
        $this->skipUnlessPropertyHooksEnabled();
    }


public function testVmCoalesceOnSeparateBackingWithoutGetHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $x {
        get { throw new Exception('get must not run for ??'); }
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
        self::assertSame("string(1) \"a\"\nok\n", ob_get_clean());
    }

    public function testVmCoalesceOnUninitializedSeparateBackingUsesDefault(): void
    {
        $code = <<<'PHP'
<?php
class U {
    public string $x {
        get { throw new Exception('get must not run for unset ??'); }
        set => $this->backing = $value;
    }
    private string $backing;
}
$u = new U();
unset($u->x);
var_dump($u->x ?? 'default');
echo "unset ok\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("string(7) \"default\"\nunset ok\n", ob_get_clean());
    }
}
