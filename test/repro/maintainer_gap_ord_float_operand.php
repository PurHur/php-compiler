<?php
declare(strict_types=1);
try {
    echo 'ord(65.9)=' . ord(65.9) . "\n";
} catch (Throwable $e) {
    echo 'ord(65.9)=EX:' . get_class($e) . ':' . $e->getMessage() . "\n";
}
