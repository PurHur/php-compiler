<?php
// #28641 — AOT array-literal unpack [...$a] must match Zend (not abort).
// @differential-repeat: 10
$a = [1, 2];
echo implode(',', [0, ...$a, 3]), "\n";