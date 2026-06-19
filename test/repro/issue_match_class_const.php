<?php

class C {
    public const int X = match (2) { 1 => 10, 2 => 20, default => 0 };
}

var_export(C::X);
