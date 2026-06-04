<?php
class C {
    public static function f() {
        var_dump(isset($this));
    }
}
C::f();
