<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\Runtime;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/** @covers issue #3317, #4823 */
#[Group('LazyObject')]
final class LazyObjectTest extends TestCase
{
    protected function setUp(): void
    {
        if (!CompilerVersion::supportsLazyObjectFactories()) {
            $this->markTestSkipped('Lazy object factories require stable PHP 8.4+ profile (#12375)');
        }
    }

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

    /** @covers issue #6052 */
    public function testClassHasLazyObjectInitializer(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
var_export(class_has_lazy_object_initializer($lazy));
echo "\n";
var_export(class_has_lazy_object_initializer(new Svc()));
echo "\n";
$ref->markLazyObjectAsInitialized($lazy);
var_export(class_has_lazy_object_initializer($lazy));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'class_has_lazy_object_initializer.php'));
        $this->assertSame("true\nfalse\nfalse\n", ob_get_clean());
    }

    /** @covers issue #6097 */
    public function testClassHasLazyObjectUninitializer(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public function __construct(public string $id = '') {}
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn (): Svc => new Svc('proxy'));
var_export(class_has_lazy_object_uninitializer($lazy));
echo "\n";
var_export(class_has_lazy_object_uninitializer(new Svc('eager')));
echo "\n";
$lazy->id;
var_export(class_has_lazy_object_uninitializer($lazy));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'class_has_lazy_object_uninitializer.php'));
        $this->assertSame("true\nfalse\nfalse\n", ob_get_clean());
    }

    /** @covers issue #6096 */
    public function testLazyGhostTraitBuiltinMarker(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
var_export(trait_exists('LazyGhostTrait'));
echo "\n";
class Svc {
    use LazyGhostTrait;
    public string $id = '';
    public function __construct(string $id = '') {
        $this->id = $id;
    }
}
echo "compiled\n";
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) {
    $o->__construct('lazy');
});
echo $ref->isUninitializedLazyObject($lazy) ? 'uninit' : 'init', "\n";
echo $lazy->id, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_ghost_trait.php'));
        $this->assertSame("false\ncompiled\nuninit\nlazy\n", ob_get_clean());
    }

    /** @covers issue #6531 */
    public function testLazyGhostTraitCreateLazyGhost(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    use LazyGhostTrait;
    public string $id = 'init';
}
$lazy = Svc::createLazyGhost(function (Svc $o): void {
    $o->id = 'lazy';
});
echo $lazy->id, "\n";
$ghost = Svc::createLazyGhost(function (Svc $o): void {
    $o->id = 'never';
});
$ghost->markLazyObjectAsInitialized();
echo $ghost->id, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_ghost_create.php'));
        $this->assertSame("lazy\ninit\n", ob_get_clean());
    }

    /** @covers issue #6125 */
    public function testResetAsLazyObject(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public string $id = '';
    public function __construct(string $tag = '') {
        $this->id = $tag;
    }
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) {
    $o->__construct('init');
});
$ref->markLazyObjectAsInitialized($lazy);
$ref->resetAsLazyObject($lazy);
echo $ref->isUninitializedLazyObject($lazy) ? 'uninit' : 'init', "\n";
echo $lazy->id, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_object_reset.php'));
        $this->assertSame("uninit\ninit\n", ob_get_clean());
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

    /** @covers issue #6776 */
    public function testLazyProxyReflectionMethods(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public function __construct(public string $id = 'x') {}
}
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyProxy(static fn () => new Svc('proxy'));
echo 'methods=';
echo (int) method_exists($ref, 'getLazyProxyFactory');
echo (int) method_exists($ref, 'resetAsLazyProxy');
echo "\n";
$factory = $ref->getLazyProxyFactory($lazy);
echo 'pending_factory=', (null === $factory ? 'null' : 'callable'), "\n";
echo 'before=', $lazy->id, "\n";
$ref->resetAsLazyProxy($lazy, static fn () => new Svc('rebound'));
echo 'rebound_before=', $lazy->id, "\n";
echo 'after_rebound=', $lazy->id, "\n";
$ref->markLazyObjectAsInitialized($lazy);
echo 'after_mark_factory=', (null === $ref->getLazyProxyFactory($lazy) ? 'null' : 'callable'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_proxy_reflection.php'));
        $this->assertSame(
            "methods=11\npending_factory=callable\nbefore=proxy\nrebound_before=rebound\nafter_rebound=rebound\nafter_mark_factory=null\n",
            ob_get_clean()
        );
    }

    /** @covers issue #12310 */
    public function testCreateLazyProxyAcceptsVoidFactoryReturn(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public int $v = 0;
}
$proxy = createLazyProxy(Svc::class, function (Svc $o): void {
    $o->v = 99;
});
echo $proxy->v, "\n";
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'create_lazy_proxy_void_factory.php'));
        $this->assertSame("99\nok\n", ob_get_clean());
    }

    /** @covers issue #12309 */
    public function testCreateLazyGhostIgnoresObjectReturnFromInitializer(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc {
    public int $v = 0;
}
$ghost = createLazyGhost(Svc::class, function (Svc $o) {
    $o->v = 42;
    return $o;
});
echo $ghost->v, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'create_lazy_ghost_object_return.php'));
        $this->assertSame("42\n", ob_get_clean());
    }
}
