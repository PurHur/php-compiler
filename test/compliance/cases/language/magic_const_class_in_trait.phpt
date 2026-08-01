--TEST--
language: __CLASS__ inside trait methods is the using class (#26459)
--FILE--
<?php
trait T {
    public function f() { return __CLASS__; }
    public static function s() { return __CLASS__; }
    public function m() {
        $inner = function () { return __CLASS__; };
        return $inner();
    }
    public function meta() { return __TRAIT__ . '|' . __METHOD__; }
}
class C { use T; }
class D { use T; }
echo (new C)->f(), ',', (new D)->f(), "\n";
echo 'static=', C::s(), ',', D::s(), "\n";
echo 'closure=', (new C)->m(), "\n";
echo 'meta=', (new C)->meta(), "\n";
--EXPECT--
C,D
static=C,D
closure=C
meta=T|T::meta
