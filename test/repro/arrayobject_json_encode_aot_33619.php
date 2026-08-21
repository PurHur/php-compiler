<?php
// #33619 — AOT json_encode(ArrayObject/ArrayIterator) must encode __spl_ht like Zend.
echo 'ao=', json_encode(new ArrayObject(['x' => 1, 'y' => 2])), "\n";
echo 'ai=', json_encode(new ArrayIterator(['a' => 1])), "\n";
echo 'packed=', json_encode(new ArrayObject([1, 2, 3])), "\n";
echo 'empty=', json_encode(new ArrayObject([])), "\n";
echo 'std=', json_encode((object) ['x' => 1]), "\n";
