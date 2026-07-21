--TEST--
stdlib http_build_query() top-level object $data — public props only (#21950, ext/standard/http.c)
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
echo http_build_query(['a' => 1]), "\n";
try {
    http_build_query(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
a=1&b=x
""
k=v
a=1
http_build_query(): Argument #1 ($data) must be of type array, null given
