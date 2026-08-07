--TEST--
Language: isset()/empty() on object property as call arg returns bool (#28622, Zend/zend_execute / isset)
--FILE--
<?php
class C {
    public $a = 1;
    public $b;
}
$c = new C;
echo var_export(isset($c->a), true), "\n";
echo var_export(isset($c->b), true), "\n";
echo var_export(isset($c->c), true), "\n";
echo var_export(empty($c->a), true), "\n";
echo var_export(empty($c->b), true), "\n";
echo gettype(isset($c->a)), "\n";
--EXPECT--
true
false
false
false
true
boolean
