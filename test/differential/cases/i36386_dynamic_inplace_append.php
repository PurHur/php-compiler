<?php
// @differential-repeat: 5 large dynamic $buf .= must stay Zend-identical under AOT (#36386)
// n=200 exceeds the pre-fix heap-corruption threshold (~170) for {main} appendInPlace.
$buf = '';
for ($i = 0; $i < 200; $i++) {
    $buf .= 'row-'.$i.';';
}
$n = strlen($buf);
echo $n, '|', substr($buf, 0, 14), '|', substr($buf, $n - 8), '|', md5($buf), "\n";
