--TEST--
Language: __TRAIT__ scope — trait body vs class/global (#3609)
--FILE--
<?php
trait T {
    public function tm() { echo "trait:", __TRAIT__, "|\n"; }
}
class C { use T; public function cm() { echo "class:", __TRAIT__, "|\n"; } }
$c = new C;
$c->tm();
$c->cm();
echo __TRAIT__, "|\n";
--EXPECT--
trait:T|
class:|
|
