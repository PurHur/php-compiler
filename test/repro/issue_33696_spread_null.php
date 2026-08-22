<?php
// #33696: [...$a] / ArrayObject|ArrayIterator construct must keep null (not isset holes).
$a = [null, 1];
echo 'spread:', count([...$a]), "\n";
echo 'ao:', count(new ArrayObject([null])), "\n";
$it = new ArrayIterator([null]);
echo 'ai:', count($it), "\n";
echo 'ai_ser:', serialize($it), "\n";
