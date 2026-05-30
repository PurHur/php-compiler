<?php
class C {
    public static int $x = 42;
    public const Y = 99;
}
$n = 'x';
echo C::{$n}, "\n";
$n = 'Y';
echo C::{$n}, "\n";
