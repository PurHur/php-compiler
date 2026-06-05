<?php
$a = ['a' => 1];
try {
    $a++;
    var_dump($a);
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
