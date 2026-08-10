--TEST--
Language: empty/isset illegal array offset share isset-or-empty TypeError (#29567)
--FILE--
<?php
$a = [];

try {
    var_export(empty($a[[]]));
} catch (Throwable $e) {
    echo 'empty: ', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    var_export(isset($a[[]]));
} catch (Throwable $e) {
    echo 'isset: ', get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
empty: TypeError: Illegal offset type in isset or empty
isset: TypeError: Illegal offset type in isset or empty
