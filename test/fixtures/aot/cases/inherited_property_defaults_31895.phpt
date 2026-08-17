--TEST--
AOT: inherited property defaults apply on subclass new (#31895, zend_objects.c)
--FILE--
<?php
class ATyped31895 {
    public string $p = 'hi';
}
class BTyped31895 extends ATyped31895 {}
echo (new ATyped31895)->p, "\n";
echo (new BTyped31895)->p, "\n";

class AUntyped31895 {
    public $p = 'hi';
}
class BUntyped31895 extends AUntyped31895 {}
echo (new AUntyped31895)->p, "\n";
echo (new BUntyped31895)->p, "\n";

class AMid31895 {
    public string $p = 'hi';
}
class BMid31895 extends AMid31895 {}
class CMid31895 extends BMid31895 {}
echo (new CMid31895)->p, "\n";
--EXPECT--
hi
hi
hi
hi
hi
--EXPECT_EXIT--
0
