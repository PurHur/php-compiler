<?php
/** Maintainer gap: RecursiveTreeIterator(null $flags) missing E_DEPRECATED + wrong key prefixes (ext/spl/spl_iterators.c). */
error_reporting(E_ALL);
ini_set('display_errors', '1');

$t = new RecursiveTreeIterator(new RecursiveArrayIterator(['a' => 1, 'b' => [2]]), null);
$out = [];
foreach ($t as $k => $v) {
    $out[$k] = $v;
}
echo json_encode($out) . "\n";
