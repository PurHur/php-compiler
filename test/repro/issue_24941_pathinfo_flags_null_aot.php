<?php
/** AOT: pathinfo null flags coerce to 0 → empty string (#24941). */
$n = @pathinfo('/a/b.txt', null);
echo 'null_type=', gettype($n), "\n";
echo 'null_eq=', ($n === '') ? 'yes' : 'no', "\n";
$z = pathinfo('/a/b.txt', 0);
echo 'zero_eq=', ($z === '') ? 'yes' : 'no', "\n";
