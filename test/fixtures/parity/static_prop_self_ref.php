<?php
class C {
    public static $x;
}
C::$x = &C::$x;
var_dump(C::$x);
