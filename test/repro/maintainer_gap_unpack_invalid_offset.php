<?php
declare(strict_types=1);
try {
    unpack('C', "\x01", 99);
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}
