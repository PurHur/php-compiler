--TEST--
Language: trait instance properties — compile, merge, and access (#4779, Zend/zend_traits.c)
--FILE--
<?php
trait T {
    public string $label = 'ok';
}
class C {
    use T;
}
$c = new C();
echo $c->label, "\n";
--EXPECT--
ok
