<?php
/**
 * Issue #28622 — AOT isset($obj->prop) as call arg must be bool, not NULL.
 * php-src: Zend/zend_vm_def.h / zend_execute.h (property isset)
 */
class C {
    public $a = 1;
    public $b;
}
$c = new C;
var_export(isset($c->a));
echo "\n";
var_export(isset($c->b));
echo "\n";
var_export(isset($c->c));
echo "\n";
