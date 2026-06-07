--TEST--
stdlib chunk_split() JIT — backed enum case separator TypeError (#6032)
--FILE--
<?php
enum ES: string { case X = '-'; }
try {
    chunk_split('abc', 2, ES::X);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
chunk_split(): Argument #3 ($separator) must be of type string, ES given
