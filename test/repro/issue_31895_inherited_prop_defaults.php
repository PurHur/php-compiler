<?php
// #31895 — AOT inherited property defaults (zend_objects.c / zend_inheritance.c)
class ATyped {
    public string $p = 'hi';
}
class BTyped extends ATyped {}
echo (new ATyped)->p, "\n";
echo (new BTyped)->p, "\n";

class AUntyped {
    public $p = 'hi';
}
class BUntyped extends AUntyped {}
echo (new AUntyped)->p, "\n";
echo (new BUntyped)->p, "\n";

class AMid {
    public string $p = 'hi';
}
class BMid extends AMid {}
class CMid extends BMid {}
echo (new CMid)->p, "\n";
