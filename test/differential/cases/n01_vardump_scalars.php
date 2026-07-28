<?php
// var_dump() of bool/int/float. Passes AOT — locks the coverage in, and is the contrast that makes
// n02 attributable: the scalar machinery exists and works for three of the five scalar types.
var_dump(true);
var_dump(false);
var_dump(42);
var_dump(1.5);
