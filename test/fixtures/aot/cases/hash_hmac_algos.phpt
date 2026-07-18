--TEST--
AOT hash_hmac_algos() — full HMAC algorithm list (#6229, #6365, #20652)
--FILE--
<?php
$algos = hash_hmac_algos();
echo is_array($algos) ? "array\n" : "not_array\n";
echo array_is_list($algos) ? "list\n" : "assoc\n";
// User-script foreach (not in_array NestedJIT helper — iterate foreach is AOT-broken, #20652).
$has256 = false;
$has512 = false;
foreach ($algos as $algo) {
    if ('sha256' === $algo) {
        $has256 = true;
    }
    if ('sha512' === $algo) {
        $has512 = true;
    }
}
echo $has256 ? "has_sha256\n" : "no_sha256\n";
echo $has512 ? "has_sha512\n" : "no_sha512\n";
echo count($algos) === 44 ? "forty_four_algos\n" : "wrong_count\n";
--EXPECT--
array
list
has_sha256
has_sha512
forty_four_algos
