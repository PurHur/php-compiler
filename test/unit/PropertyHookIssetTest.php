<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** isset() on property hooks must not invoke get hook (#8917). */
final class PropertyHookIssetTest extends TestCase
{
    public function testVmIssetOnVirtualGetHookDoesNotInvokeGet(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public ?string $x {
        get { throw new Exception('get must not run for isset'); }
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
        self::assertSame("bool(false)\nok\n", ob_get_clean());
    }

    public function testVmIssetOnSeparateBackingWithoutGetHook(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $x {
        get { throw new Exception('get must not run for isset'); }
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
        self::assertSame("bool(true)\n", ob_get_clean());
    }
}
