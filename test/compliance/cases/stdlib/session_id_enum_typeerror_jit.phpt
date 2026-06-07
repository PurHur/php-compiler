--TEST--
stdlib session_id() JIT — enum case TypeError (#6581)
--FILE--
<?php
declare(strict_types=1);

enum E: string {
    case A = 'sessid';
}

try {
    session_id(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}

echo session_id(null), "\n";
echo session_id('abc123'), "\n";
echo session_id(), "\n";
--EXPECT--
session_id(): Argument #1 ($id) must be of type ?string, E given


abc123
