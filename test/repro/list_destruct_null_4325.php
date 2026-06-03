<?php
[$a, $b] = null;
echo "a=", var_export($a, true), " b=", var_export($b, true), "\n";
list($x) = false;
echo "x=", var_export($x, true), "\n";
[$y] = 0;
echo "y=", var_export($y, true), "\n";
