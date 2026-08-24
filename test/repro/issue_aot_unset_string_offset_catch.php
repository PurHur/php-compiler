<?php
$s = 'abc';
try {
    unset($s[1]);
    echo 'survived:', $s, "\n";
} catch (Error $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
