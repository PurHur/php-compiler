<?php
/** Repro #21950 — http_build_query() top-level object $data (php-src http.c). */
class O {
    public $a = 1;
    private $secret = 9;
    public $b = 'x';
}
echo http_build_query(new O), "\n";
echo json_encode(http_build_query(new ArrayObject(['a' => 1]))), "\n";
echo http_build_query((object)['k' => 'v']), "\n";
