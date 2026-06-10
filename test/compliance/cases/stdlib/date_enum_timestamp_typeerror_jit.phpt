--TEST--
stdlib date()/gmdate() JIT — enum timestamp TypeError (#5842)
--FILE--
<?php
enum I: int { case A = 1; }
try {
    date('Y-m-d', I::A);
    echo "date uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
try {
    gmdate('Y', I::A);
    echo "gmdate uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
date(): Argument #2 ($timestamp) must be of type ?int, I given
gmdate(): Argument #2 ($timestamp) must be of type ?int, I given
