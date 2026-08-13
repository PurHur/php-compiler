--TEST--
AOT hash_algos() — full digest algorithm list (#11463, #28750, #30794)
--FILE--
<?php
$algos = hash_algos();
echo is_array($algos) ? "array\n" : "not_array\n";
echo array_is_list($algos) ? "list\n" : "assoc\n";
echo 'count=', count($algos), "\n";
$hasMd5 = false;
$hasSha512 = false;
$hasXxh3 = false;
foreach ($algos as $algo) {
    if ('md5' === $algo) {
        $hasMd5 = true;
    }
    if ('sha512' === $algo) {
        $hasSha512 = true;
    }
    if ('xxh3' === $algo) {
        $hasXxh3 = true;
    }
}
echo $hasMd5 ? "has_md5\n" : "no_md5\n";
echo $hasSha512 ? "has_sha512\n" : "no_sha512\n";
echo $hasXxh3 ? "has_xxh3\n" : "no_xxh3\n";
--EXPECT--
array
list
count=60
has_md5
has_sha512
has_xxh3
