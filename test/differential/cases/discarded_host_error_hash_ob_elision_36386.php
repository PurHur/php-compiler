<?php
// Discarded gethostname/error_get_last/getrusage/hash_algos/
// hash_hmac_algos/ob_get_contents/ob_get_length/headers_list must match
// Zend (#36386). Side-effect-free statements only — results unused except
// shape checks on live builtins that compile/run cleanly under AOT.
// Live error_get_last/getrusage omitted: NestedJIT / process tables vary.
// Live ob_get_contents omitted: false vs empty-string host variance.
// php-src: ext/standard/basic_functions.c, ext/hash/hash.c,
// ext/standard/output.c, ext/standard/head.c
// @differential-repeat: 3
function work(int $loops, int $mode): int
{
    $c = 0;
    for ($k = 0; $k < $loops; ++$k) {
        gethostname();
        error_get_last();
        getrusage();
        getrusage($mode);
        hash_algos();
        hash_hmac_algos();
        ob_get_contents();
        ob_get_length();
        headers_list();
        $c += $k;
    }

    $host = gethostname();
    $algos = hash_algos();
    $hmac = hash_hmac_algos();
    $hlen = ob_get_length();
    $hdrs = headers_list();

    return $c
        + (is_string($host) && $host !== '' ? 1 : 0)
        + (is_array($algos) && count($algos) > 0 ? 1 : 0)
        + (is_array($hmac) && count($hmac) > 0 ? 1 : 0)
        + (false === $hlen || (is_int($hlen) && $hlen >= 0) ? 1 : 0)
        + (is_array($hdrs) ? 1 : 0);
}
echo work(5, 0), "\n";
echo work(3, 0), "\n";
echo work(2, 0), "\n";
