--TEST--
Language: __TRAIT__ scope in namespaced trait (#3609)
--FILE--
<?php
namespace App;

trait T {
    public function tm() { echo "trait:", __TRAIT__, "|\n"; }
}
class C { use T; public function cm() { echo "class:", __TRAIT__, "|\n"; } }
$c = new C;
$c->tm();
$c->cm();
--EXPECT--
trait:App\T|
class:|
