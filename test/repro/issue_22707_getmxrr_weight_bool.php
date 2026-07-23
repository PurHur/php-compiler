<?php
$hosts = [];
$w = false;
try {
    $r = @getmxrr('php.net', $hosts, $w);
    echo ($r ? 'y' : 'n'), '|', gettype($w), '|', is_array($w) ? count($w) : -1, "\n";
} catch (Throwable $t) {
    echo get_class($t), ':', $t->getMessage(), "\n";
}
