<?php

try {
    array_replace_recursive(['a' => 1], null);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
