<?php
// AOT: yield from array must compile and match Zend key overwrite (iterator_to_array).
function gen() {
    yield from [1, 2];
    yield 3;
}
echo implode(',', iterator_to_array(gen())), "\n";
