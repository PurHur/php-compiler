<?php

$s = gc_status();
foreach (['running', 'protected', 'full', 'buffer_size'] as $key) {
    var_export(array_key_exists($key, $s));
    echo "\n";
}
