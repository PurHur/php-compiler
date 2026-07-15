--TEST--
stdlib session_name() JIT — enum case TypeError (#6536)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'PHPSESSID';
}

ob_start();
try {
    session_name(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

echo session_name(null), "\n";
echo session_name('CUSTOM'), "\n";
echo session_name(), "\n";
--EXPECT--
session_name(): Argument #1 ($name) must be of type ?string, E given
PHPSESSID
PHPSESSID
CUSTOM
