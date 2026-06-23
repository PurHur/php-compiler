<?php
declare(strict_types=1);

try {
    join(null, ['a', 'b']);
    echo "join: no throw\n";
} catch (Throwable $e) {
    echo 'join: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}

try {
    implode(null, ['a', 'b']);
    echo "implode: no throw\n";
} catch (Throwable $e) {
    echo 'implode: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
}
