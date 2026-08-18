<?php
/** Repro for #31967 — $obj::method() / variable class in a static call. */
class U {
    public static function method() {
        echo 'U';
    }
}
$obj = new U();
$obj::method();
