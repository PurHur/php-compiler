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

    /** @covers issue #6052 / #28517 — free class_has_* phantoms; use ReflectionClass */
    public function testClassHasLazyObjectInitializer(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
var_export($ref->isUninitializedLazyObject(new Svc()));
echo "\n";
$ref->markLazyObjectAsInitialized($lazy);
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'class_has_lazy_object_initializer.php'));
        $this->assertSame("true\nfalse\nfalse\n", ob_get_clean());
    }

    /** @covers issue #18818 — property-read materialization flips introspection probes */
    public function testClassHasLazyObjectInitializerAfterPropertyRead(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
echo $ref->isUninitializedLazyObject($lazy) ? 'true' : 'false';
echo "\n";
$lazy->id;
echo $ref->isUninitializedLazyObject($lazy) ? 'true' : 'false';
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'class_has_lazy_object_initializer_read.php'));
        $this->assertSame("true\nfalse\n", ob_get_clean());
    }

    /** @covers issue #18818 — property-read materialization flips introspection probes */
    public function testIsUninitializedLazyObjectAfterPropertyRead(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class Svc { public string $id = ''; }
$ref = new ReflectionClass(Svc::class);
$lazy = $ref->newLazyGhost(function (Svc $o) { $o->id = 'x'; });
echo $ref->isUninitializedLazyObject($lazy) ? 'true' : 'false';
echo "\n";
$lazy->id;
echo $ref->isUninitializedLazyObject($lazy) ? 'true' : 'false';
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'is_uninitialized_lazy_object_read.php'));
        $this->assertSame("true\nfalse\n", ob_get_clean());
    }

    /** @covers issue #6097 / #28517 — free class_has_* phantoms; use ReflectionClass */
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
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
var_export($ref->isUninitializedLazyObject(new Svc('eager')));
echo "\n";
$lazy->id;
var_export($ref->isUninitializedLazyObject($lazy));
echo "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'class_has_lazy_object_uninitializer.php'));
        $this->assertSame("true\nfalse\nfalse\n", ob_get_clean());
    }

    /** @covers issue #28517 — free class_has_lazy_object_* never registered */
    public function testClassHasLazyObjectFreeFunctionsAbsent(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo (int) function_exists('class_has_lazy_object_initializer'), "\n";
echo (int) function_exists('class_has_lazy_object_uninitializer'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'class_has_lazy_object_phantoms.php'));
        $this->assertSame("0\n0\n", ob_get_clean());
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

    /** @covers issue #6125 / #28516 — reset via resetAsLazyGhost (php-src); resetAsLazyObject is phantom */
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
$ref->resetAsLazyGhost($lazy, function (Svc $o) {
    $o->__construct('init');
});
echo $ref->isUninitializedLazyObject($lazy) ? 'uninit' : 'init', "\n";
echo $lazy->id, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_object_reset.php'));
        $this->assertSame("uninit\ninit\n", ob_get_clean());
    }

    /** @covers issue #29152 — getLazyInitializer() === factory Closure */
    public function testGetLazyInitializerReturnsSameClosureInstance(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 1;
}
$r = new ReflectionClass(C::class);
$init = function (C $obj) {
    $obj->x = 4;
};
$o = $r->newLazyGhost($init);
echo $r->getLazyInitializer($o) === $init ? "ghost_same\n" : "ghost_diff\n";
echo $r->getLazyInitializer($o) === $init ? "ghost_again\n" : "ghost_again_diff\n";
$proxyInit = static function (): C {
    return new C();
};
$p = $r->newLazyProxy($proxyInit);
echo $r->getLazyInitializer($p) === $proxyInit ? "proxy_same\n" : "proxy_diff\n";
$resetInit = function (C $obj) {
    $obj->x = 9;
};
$plain = new C();
$r->resetAsLazyGhost($plain, $resetInit);
echo $r->getLazyInitializer($plain) === $resetInit ? "reset_same\n" : "reset_diff\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_initializer_identity.php'));
        $this->assertSame(
            "ghost_same\nghost_again\nproxy_same\nreset_same\n",
            ob_get_clean()
        );
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

    /** @covers issue #6776 / #28516 — getLazyProxyFactory is phantom; use getLazyInitializer */
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
$factory = $ref->getLazyInitializer($lazy);
echo 'pending_factory=', (null === $factory ? 'null' : 'callable'), "\n";
echo 'before=', $lazy->id, "\n";
$ref->resetAsLazyProxy($lazy, static fn () => new Svc('rebound'));
echo 'rebound_before=', $lazy->id, "\n";
echo 'after_rebound=', $lazy->id, "\n";
$ref->markLazyObjectAsInitialized($lazy);
echo 'after_mark_factory=', (null === $ref->getLazyInitializer($lazy) ? 'null' : 'callable'), "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_proxy_reflection.php'));
        $this->assertSame(
            "methods=01\npending_factory=callable\nbefore=proxy\nrebound_before=rebound\nafter_rebound=rebound\nafter_mark_factory=null\n",
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
$rc = new ReflectionClass(Svc::class);
$proxy = $rc->newLazyProxy(function (): Svc {
    $o = new Svc();
    $o->v = 99;
    return $o;
});
echo $proxy->v, "\n";
echo "ok\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'create_lazy_proxy_void_factory.php'));
        $this->assertSame("99\nok\n", ob_get_clean());
    }

    /** @covers issue #29151 */
    public function testNewLazyProxyFactoryReturningProxyThrowsError(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
class C {
    public int $x = 1;
}
$r = new ReflectionClass(C::class);
$proxy = $r->newLazyProxy(function ($obj) {
    return $obj;
});
try {
    echo $proxy->x;
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage();
}
echo "\n";
$ok = $r->newLazyProxy(function (): C {
    $o = new C();
    $o->x = 9;
    return $o;
});
echo $ok->x, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_proxy_factory_ret_lazy.php'));
        $this->assertSame(
            "Error:Lazy proxy factory must return a non-lazy object\n9\n",
            ob_get_clean()
        );
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
$rc = new ReflectionClass(Svc::class);
$ghost = $rc->newLazyGhost(function (Svc $o) {
    $o->v = 42;
    return $o;
});
echo $ghost->v, "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'create_lazy_ghost_object_return.php'));
        $this->assertSame("42\n", ob_get_clean());
    }

    /** @covers issue #21126 */
    public function testSerializeInitializesLazyGhostUnlessSkipFlag(): void
    {
        $runtime = new Runtime();
        $code = <<<'PHP'
<?php
echo defined('ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE') ? 'Y' : 'N', "\n";
echo defined('ReflectionClass::SKIP_DESTRUCTOR') ? 'Y' : 'N', "\n";
echo ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE, "\n";
echo ReflectionClass::SKIP_DESTRUCTOR, "\n";
class A {
    public int $x;
    public function __construct() {
        $this->x = 7;
        echo "ctor\n";
    }
}
$r = new ReflectionClass(A::class);
$init = function (A $obj) { $obj->__construct(); };
$o = $r->newLazyGhost($init);
echo 'uninit=', $r->isUninitializedLazyObject($o) ? 'Y' : 'N', "\n";
$s = serialize($o);
echo $s, "\n";
echo 'uninit_after=', $r->isUninitializedLazyObject($o) ? 'Y' : 'N', "\n";
$o2 = unserialize($s);
echo 'x=', $o2->x, "\n";
$skip = $r->newLazyGhost($init, ReflectionClass::SKIP_INITIALIZATION_ON_SERIALIZE);
echo 'skip_uninit=', $r->isUninitializedLazyObject($skip) ? 'Y' : 'N', "\n";
echo serialize($skip), "\n";
echo 'skip_uninit_after=', $r->isUninitializedLazyObject($skip) ? 'Y' : 'N', "\n";
PHP;
        ob_start();
        $runtime->run($runtime->parseAndCompile($code, 'lazy_serialize_skip.php'));
        $this->assertSame(
            "Y\nY\n8\n16\nuninit=Y\nctor\nO:1:\"A\":1:{s:1:\"x\";i:7;}\nuninit_after=N\nx=7\n"
            ."skip_uninit=Y\nO:1:\"A\":0:{}\nskip_uninit_after=Y\n",
            ob_get_clean()
        );
    }
}
