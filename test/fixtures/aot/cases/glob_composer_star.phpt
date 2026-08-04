--TEST--
AOT: glob() returns match array not false (#27235)
--FILE--
<?php
$g = glob('composer.*');
if (is_array($g)) {
    echo 'count='.count($g).' '.implode(',', $g);
} else {
    echo 'NOTARRAY:'.var_export($g, true);
}
echo "\n";
--EXPECT--
count=2 composer.json,composer.lock
