--TEST--
Language: simple trait use JIT lowering (#3789, Zend zend_compile_traits)
--FILE--
<?php
trait T {
    public function f(): int { return 1; }
}
class C { use T; }
echo (new C)->f(), "\n";
--EXPECT--
1
