<?php

try {
    implode(',', 'x');
    echo "no throw\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
