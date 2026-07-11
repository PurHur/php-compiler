--TEST--
stdlib sprintf() %F/%G/%E/%f non-finite inside inline array literal (#12764, ext/standard/sprintf.c)
--FILE--
<?php
$lines = [
    sprintf('%F', NAN),
    sprintf('%G', NAN),
    sprintf('%E', NAN),
    sprintf('%f', NAN),
    sprintf('%F', INF),
];
echo implode(',', $lines), "\n";
--EXPECT--
NaN,NaN,NaN,NaN,INF
