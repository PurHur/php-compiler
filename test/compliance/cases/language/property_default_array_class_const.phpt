--TEST--
Language: property default array with self:: keys/values (#3803, zend_compile.c)
--FILE--
<?php
class C {
    public const X = 5;
    public array $a = [self::X => self::X];
    public function show(): void {
        var_dump($this->a);
    }
}
(new C)->show();
--EXPECT--
array(1) {
  [5]=>
  int(5)
}
