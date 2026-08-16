<?php
/** Maintainer gap: SplFixedArray::fromArray(null $preserveKeys) missing E_DEPRECATED (ext/spl/spl_fixedarray.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo json_encode(iterator_to_array(SplFixedArray::fromArray([1, 2], null))) . "\n";
