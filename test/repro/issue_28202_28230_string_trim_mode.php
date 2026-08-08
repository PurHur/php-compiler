<?php
try {
    trim(' x ', ' ', true);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), PHP_EOL;
}
echo 'enum=', enum_exists('StringTrimMode') ? '1' : '0', PHP_EOL;
