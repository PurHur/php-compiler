--TEST--
stdlib array_chunk(range(), N, true) inline hoisted haystack (#11767, #17862, ext/standard/array.c)
--FILE--
<?php

declare(strict_types=1);

$chunks = array_chunk(range(1, 5), 2, true);
echo 'count=', count($chunks), "\n";
--EXPECT--
count=3
