<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** isset() on property hooks invokes get hook (#9107). */
final class PropertyHookIssetTest extends TestCase
{
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
}
