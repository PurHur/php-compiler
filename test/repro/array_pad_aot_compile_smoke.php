<?php

declare(strict_types=1);

// AOT compile smoke: array_pad() must link after #14993 pad_type split (#12476 bridge arity).
$a = array_pad([1, 2], 5, 'p');
echo count($a), ':', $a[4], "\n";
