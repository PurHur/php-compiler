<?php
try {
    $a = [];
    ++$a;
} catch (TypeError $e) {
    echo 'caught: ', $e->getMessage(), "\n";
    exit(0);
}
echo "not caught\n";
exit(1);
