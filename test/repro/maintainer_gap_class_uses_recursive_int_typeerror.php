<?php

declare(strict_types=1);

try {
    class_uses_recursive(123);
    echo "fail: no exception\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
