<?php
interface I {
    public const X = 1;
}
class C implements I {}
var_dump(C::X, I::X);
