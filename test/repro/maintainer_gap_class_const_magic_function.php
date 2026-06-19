<?php

class C {
    public const X = __CLASS__ . '::' . __FUNCTION__;
}

echo C::X, "\n";
