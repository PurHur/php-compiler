<?php
try {
    serialize(new finfo());
    echo "serialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    unserialize('O:5:"finfo":0:{}');
    echo "unserialize:no-throw\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
