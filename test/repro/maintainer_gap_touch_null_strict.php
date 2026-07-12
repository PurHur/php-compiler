<?php

declare(strict_types=1);

try {
    touch(null);
    echo "result=false\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
