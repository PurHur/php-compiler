--TEST--
Language: match default arm throw (#3398)
--FILE--
<?php
try {
    echo match (1) {
        0 => 'zero',
        default => throw new Exception(),
    };
} catch (Exception $e) {
    echo "default-throw\n";
}
--EXPECT--
default-throw
