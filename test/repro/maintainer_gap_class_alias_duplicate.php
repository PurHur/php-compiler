<?php
declare(strict_types=1);

try {
    class_alias('stdClass', 'stdClass');
    echo "alias ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
