<?php
class C {
    public int $x;
}
$c = new C();
var_export(get_object_vars($c));
