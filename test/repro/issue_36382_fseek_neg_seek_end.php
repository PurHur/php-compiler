<?php
/**
 * #36382 / #16523 — fseek($f, -1, SEEK_END) UnaryMinus+ConstFetch hoist must stay green.
 */
$f = fopen('php://memory', 'w+');
fwrite($f, 'abc');
fseek($f, -1, SEEK_END);
echo ftell($f), "\n";
