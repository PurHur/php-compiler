<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPCompiler\VM\ObjectRegistry;
use PHPCompiler\VM\WeakRefRegistry;
use PHPUnit\Framework\TestCase;

/** @covers issue #3282 */
final class WeakRefRegistryMapTest extends TestCase
{
    public function testOffsetSetRegistersWeakMapKeyTarget(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$m = new WeakMap();
$m->offsetSet(new Box(), 1);
PHP;
        $runtime->run($runtime->parseAndCompile($code, 'reg.php'));
        $this->assertSame(1, WeakRefRegistry::registeredMapTargetCount());
        $ids = WeakRefRegistry::weakMapKeyTargetIds();
        $this->assertCount(1, $ids);
        $this->assertNotNull(ObjectRegistry::find($ids[0]));
    }

    public function testGcClearsWeakMapRegistryEntry(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Box {}
$m = new WeakMap();
$m->offsetSet(new Box(), 1);
gc_collect_cycles();
PHP;
        $runtime->run($runtime->parseAndCompile($code, 'reg_gc.php'));
        $this->assertSame(0, WeakRefRegistry::registeredMapTargetCount());
    }
}
