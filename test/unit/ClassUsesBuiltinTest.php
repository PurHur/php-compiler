<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** class_uses() VM builtin (issue #3119). */
final class ClassUsesBuiltinTest extends TestCase
{
    public function testVmClassUsesTraitMap(): void
    {
        $code = <<<'PHP'
<?php
trait T { public function m(): int { return 1; } }
class C { use T; }
$u = class_uses(C::class);
echo count($u), "\n";
echo $u['T'] === 'T' ? '1' : '0';
PHP;
        $rt = new Runtime();
        $block = $rt->parseAndCompile($code, 'class_uses.php');
        ob_start();
        $rt->run($block);
        $this->assertSame("1\n1", ob_get_clean());
    }
}
