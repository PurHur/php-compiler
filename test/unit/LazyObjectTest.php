<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #3317 */
#[Group('LazyObject')]
final class LazyObjectTest extends TestCase
{
    public function testNewLazyProxyDefersConstructor(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public function __construct(public string $id = '') {
        echo "init\n";
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn () => new Svc('x'));
echo "before\n";
echo $lazy->id, "\n";
echo $lazy->id, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_object_proxy.php'));
        $this->assertSame("before\ninit\nx\nx\n", ob_get_clean());
    }
}
