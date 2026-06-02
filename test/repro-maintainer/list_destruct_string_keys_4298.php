<?php
try {
    list($a, $b) = ['x' => 1, 'y' => 2];
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
