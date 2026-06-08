<?php
echo strtr(123, '1', '9'), "\n";
echo strtr(true, ['1' => '9']), "\n";
try {
    strtr([], 'a', 'b');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
