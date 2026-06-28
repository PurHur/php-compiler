--TEST--
Language: implements Iterator implies Traversable — compiles (#13326)
--FILE--
<?php
class C implements Iterator {
    public function current() { return null; }
    public function key() { return null; }
    public function next() {}
    public function rewind() {}
    public function valid() { return false; }
}
echo (new C()) instanceof Traversable ? "ok\n" : "fail\n";
--EXPECT--
ok
