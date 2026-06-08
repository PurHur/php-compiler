<?php
declare(strict_types=1);

$cols = array(array(3, 1, 2), array('c', 'a', 'b'));
array_multisort(...$cols);
echo $cols[0][0], ',', $cols[0][2], ',', $cols[1][0], ',', $cols[1][2], "\n";
