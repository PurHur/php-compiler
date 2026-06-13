<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/** @covers issue #4434 */
final class WeakMapIteratorTest extends TestCase
{
    public function testWeakMapForeachYieldsObjectKeys(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
$m = new WeakMap();
$o = new stdClass();
$m[$o] = 'payload';
foreach ($m as $k => $v) {
    echo (is_object($k) && $k instanceof stdClass ? '1' : '0');
    echo ($v === 'payload' ? '1' : '0');
}
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'weakmap_foreach.php'));
        $this->assertSame('11', ob_get_clean());
    }
}
