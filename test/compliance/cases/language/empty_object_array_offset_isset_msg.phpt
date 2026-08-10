--TEST--
Language: empty($arr[$object]) TypeError matches isset "in isset or empty" (#29549)
--FILE--
<?php
class O {}
$a = [];
try {
    var_export(empty($a[new O]));
    echo "\n";
} catch (Throwable $e) {
    echo 'empty: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    var_export(isset($a[new O]));
    echo "\n";
} catch (Throwable $e) {
    echo 'isset: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
empty: TypeError: Illegal offset type in isset or empty
isset: TypeError: Illegal offset type in isset or empty
