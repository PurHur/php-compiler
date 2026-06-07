<?php
const A = [1];
$a = &A[0];
$a = 2;
var_dump(A);
