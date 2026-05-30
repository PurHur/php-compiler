<?php

try {
    str_split('', 0);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
