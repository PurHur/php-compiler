<?php
class C {
    public const ITEM = E::A;
}
enum E: int { case A = 1; }
var_export(C::ITEM);
echo "\n";
