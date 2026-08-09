--TEST--
stdlib scandir(null) — ValueError after null coercion (#11944, ext/standard/dir.c)
--FILE--
<?php
try {
    scandir(null);
    echo "unexpected_success\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
} catch (TypeError) {
    echo "TypeError\n";
}
--EXPECT--
scandir(): Argument #1 ($directory) must not be empty
