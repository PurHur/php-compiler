<?php
// Scalar var_export(return=true) — thin AOT has no array var_export without Runtime->vm (#26855).
$a = [[1, 2], [3, 4]];
echo var_export($a[1][0], true), "\n";
echo var_export($a[0][0], true), "\n";
