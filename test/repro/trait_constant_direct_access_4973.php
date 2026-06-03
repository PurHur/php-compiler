<?php
trait T {
    public const X = 7;
}
class C {
    use T;
}
var_dump(C::X);
var_dump(T::X);
