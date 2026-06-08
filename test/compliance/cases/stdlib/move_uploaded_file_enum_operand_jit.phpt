--TEST--
stdlib move_uploaded_file() JIT — enum case path operands TypeError (#6278)
--FILE--
<?php
enum E: string { case A = 'tmp'; }

try {
    move_uploaded_file(E::A, '/tmp/x');
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: move_uploaded_file(): Argument #1 ($from) must be of type string, E given
