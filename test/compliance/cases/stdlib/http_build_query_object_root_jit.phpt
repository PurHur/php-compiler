--TEST--
stdlib http_build_query() JIT top-level object $data (#21950, ext/standard/http.c)
--FILE--
<?php
class O {
    public $a = 1;
    private $secret = 9;
    public $b = 'x';
}
echo http_build_query(new O), "\n";
echo json_encode(http_build_query(new ArrayObject(['a' => 1]))), "\n";
echo http_build_query((object)['k' => 'v']), "\n";
?>
--EXPECT--
a=1&b=x
""
k=v
