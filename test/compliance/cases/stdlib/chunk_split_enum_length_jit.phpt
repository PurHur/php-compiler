--TEST--
stdlib chunk_split() JIT — backed int enum case length TypeError (#6032)
--FILE--
<?php
enum EI: int { case A = 1; }
try {
    chunk_split('abc', EI::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
chunk_split(): Argument #2 ($length) must be of type int, EI given
