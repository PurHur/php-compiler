--TEST--
Language: clone uninitialized lazy ghost initializes both (zend_lazy_objects.c, #29171)
--SKIPIF--
<?php
if (getenv('PHP_COMPILER_PROFILE') !== '8.4' && getenv('PHP_COMPILER_PROFILE') !== 'forward') {
    die('skip requires PHP_COMPILER_PROFILE=8.4');
}
?>
--FILE--
<?php
class C {
    public int $x = 1;
}
$r = new ReflectionClass(C::class);
$g = $r->newLazyGhost(function (C $o) {
    $o->x = 42;
    echo "init\n";
});
echo 'before_clone uninit=', $r->isUninitializedLazyObject($g) ? 'yes' : 'no', "\n";
$c = clone $g;
echo 'after_clone g_uninit=', $r->isUninitializedLazyObject($g) ? 'yes' : 'no',
     ' c_uninit=', $r->isUninitializedLazyObject($c) ? 'yes' : 'no', "\n";
echo 'c.x=', $c->x, "\n";
echo 'g.x=', $g->x, "\n";
--EXPECT--
before_clone uninit=yes
init
after_clone g_uninit=no c_uninit=no
c.x=42
g.x=42
