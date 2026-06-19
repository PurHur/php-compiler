<?php
declare(strict_types=1);
try {
    hex2bin('abc', true);
} catch (ValueError $e) {
    echo "ValueError\n";
} catch (Error $e) {
    echo "Error\n";
}
