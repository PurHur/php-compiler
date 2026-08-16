<?php
declare(strict_types=1);
try {
    token_get_all('<?php echo 1;', null);
    echo "OK\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
