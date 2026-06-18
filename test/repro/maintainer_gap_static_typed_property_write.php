<?php

class C
{
    public static int $n = 1;
}

var_dump(C::$n);
C::$n = 2;
var_dump(C::$n);

class U
{
    public static $n = 1;
}

var_dump(U::$n);
U::$n = 2;
var_dump(U::$n);
