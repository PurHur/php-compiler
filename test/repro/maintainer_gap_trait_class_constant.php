<?php
trait T {
    public const C = 1;
}
class C {
    use T;
}
var_dump(C::C);
