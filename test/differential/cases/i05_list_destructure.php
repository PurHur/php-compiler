<?php
// Ordinary PHP: list() destructuring, positional and keyed. Passes both backends.
[$a, $b] = [1, 2];
['p' => $p, 'q' => $q] = ['p' => 'P', 'q' => 'Q'];
echo "$a $b $p $q\n";
