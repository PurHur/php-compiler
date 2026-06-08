<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** gc_collect_cycles() invokes __destruct on cyclic graphs (#6519). */
final class GcCollectCyclesDestructTest extends TestCase
{
    public function testVmGcCollectCyclesInvokesDestructorsOnCycle(): void
    {
        $code = <<<'PHP'
<?php
#[\AllowDynamicProperties]
class Node {
    public function __destruct()
    {
        echo "dtor\n";
    }
}
$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
unset($a, $b);
gc_collect_cycles();
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'gc_cycle_destruct.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("dtor\ndtor\n", ob_get_clean());
    }
}
