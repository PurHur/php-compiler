<?php
$arr = ['a', 'b'];
$glue = '-';
try {
    implode($arr, $glue);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
