<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #3317, #4823 */
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

    public function testNewLazyGhostDefersConstructor(): void
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
$lazy = $ref->newLazyGhost(function (Svc $object) {
    $object->__construct('x');
});
echo "before\n";
echo $lazy->id, "\n";
echo $lazy->id, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_object_ghost.php'));
        $this->assertSame("before\ninit\nx\nx\n", ob_get_clean());
    }

    /** @covers issue #6054, #6068 */
    public function testIsUninitializedLazyObject(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
echo $ref->isUninitializedLazyObject($lazy) ? 'true' : 'false';
echo "\n";
echo $ref->isUninitializedLazyObject(new Svc()) ? 'true' : 'false';
echo "\n";
$ref->markLazyObjectAsInitialized($lazy);
echo $ref->isUninitializedLazyObject($lazy) ? 'true' : 'false';
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_object_is_uninitialized.php'));
        $this->assertSame("true\nfalse\nfalse\n", ob_get_clean());
    }

    /** @covers issue #5968 */
    public function testLazyObjectIntrospectionMethods(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public string $id = '';
    public function __construct(public string $tag = '') {
        $this->id = $tag;
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) {
    $o->__construct('init');
});
$init = $ref->getLazyInitializer($lazy);
echo 'pending_init=', (null === $init ? 'null' : 'callable'), "\n";
$ref->markLazyObjectAsInitialized($lazy);
echo 'marked_id=', $lazy->id, "\n";
echo 'after_mark_init=', (null === $ref->getLazyInitializer($lazy) ? 'null' : 'callable'), "\n";

$plain = new Svc('plain');
$ref->resetAsLazyGhost($plain, function (Svc $o) {
    $o->__construct('reset');
});
echo 'reset_before=', $plain->id, "\n";
echo 'reset_after=', $plain->id, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_object_introspection.php'));
        $this->assertSame(
            "pending_init=callable\nmarked_id=\nafter_mark_init=null\nreset_before=reset\nreset_after=reset\n",
            ob_get_clean()
        );
    }
}
