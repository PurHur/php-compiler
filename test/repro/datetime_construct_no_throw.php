<?php
declare(strict_types=1);
try {
    new DateTime('not-a-date');
    echo "no throw\n";
} catch (Throwable $e) {
    echo 'caught ', get_class($e), "\n";
}
