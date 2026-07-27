--TEST--
stdlib stream_select/filter_var_array/get_headers Zend stub named params (#23598)
--FILE--
<?php
declare(strict_types=1);

foreach (['stream_select', 'filter_var_array', 'get_headers'] as $f) {
    $rf = new ReflectionFunction($f);
    $n = [];
    foreach ($rf->getParameters() as $p) {
        $x = $p->getName();
        if ($p->isPassedByReference()) {
            $x = '&'.$x;
        }
        $n[] = $x;
    }
    echo $f, ': ', implode(',', $n), "\n";
}

var_export(filter_var_array(array: ['a' => '1'], options: ['a' => FILTER_VALIDATE_INT]));
echo "\n";

try {
    @get_headers(url: 'http://127.0.0.1:1/', associative: true);
    echo "headers_named_ok\n";
} catch (Throwable $t) {
    echo 'headers: ', $t->getMessage(), "\n";
}

$r = $w = $e = [];
try {
    stream_select(read: $r, write: $w, except: $e, seconds: 0);
    echo "select_ok\n";
} catch (Throwable $t) {
    echo 'select: ', get_class($t), ': ', $t->getMessage(), "\n";
}
--EXPECT--
stream_select: &read,&write,&except,seconds,microseconds
filter_var_array: array,options,add_empty
get_headers: url,associative,context
array (
  'a' => 1,
)
headers_named_ok
select: ValueError: No stream arrays were passed
