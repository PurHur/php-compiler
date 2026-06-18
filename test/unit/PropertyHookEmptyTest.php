<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** empty() on property hooks — uninitialized probes backing; initialized invokes get (#9696). */
final class PropertyHookEmptyTest extends TestCase
{
    public function testVmEmptyOnVirtualGetHookDoesNotInvokeGet(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public ?string $x {
        get { echo "get runs for empty\n"; return null; }
    }
}
$c = new C();
var_dump(empty($c->x));
echo "ok\n";
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("bool(true)\nok\n", ob_get_clean());
    }

    public function testVmEmptyOnSeparateBackingInvokesGetHookWhenInitialized(): void
    {
        $code = <<<'PHP'
<?php
class C {
    public string $x {
        get { echo "get runs for empty\n"; return $this->backing; }
        set => $this->backing = $value;
    }
    private string $backing = 'a';
}
$c = new C();
var_dump(empty($c->x));
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'test.php');
        ob_start();
        $rt->run($block);
        self::assertSame("get runs for empty\nbool(false)\n", ob_get_clean());
    }
}
