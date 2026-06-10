--TEST--
print_r()/var_dump() invoke get hooks on hooked properties (#6604, ext/standard/var.c)
--FILE--
<?php
class C {
    public string $title {
        get => 'hook:' . ($this->title ?? '');
        set => $this->title = $value;
    }
}
$o = new C();
$o->title = 'x';
print_r($o);
var_dump($o);
--EXPECTF--
C Object
(
    [title] => hook:x
)
object(C)#%d (1) {
  ["title"]=>
  string(6) "hook:x"
}
