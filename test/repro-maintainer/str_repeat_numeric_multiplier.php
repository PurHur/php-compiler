<?php

echo str_repeat('x', 2.9), "\n";
echo str_repeat('y', '3'), "\n";
try {
    str_repeat('x', -1);
} catch (ValueError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
