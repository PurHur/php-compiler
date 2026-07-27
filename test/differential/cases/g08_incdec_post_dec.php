<?php
// #23840: plain post-decrement on a typed local — must not be a no-op under AOT.
$n = 5;
$n--;
echo $n, "\n";
