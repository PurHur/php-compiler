<?php

class ParentClass {}
class ChildClass extends ParentClass {}

$r = is_a(new ChildClass(), ParentClass::class);
echo $r ? "ok\n" : "fail: is_a(object, ParentClass::class) should be true\n";
