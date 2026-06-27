<?php

declare(strict_types=1);

try {
    str_decrement('a');
    echo "dec_a=uncaught\n";
} catch (Error $e) {
    echo 'dec_a=', get_class($e), "\n";
}

echo 'inc_Z=', str_increment('Z'), "\n";
echo "ok\n";
