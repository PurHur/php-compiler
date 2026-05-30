--TEST--
Language: match throw arm propagates exception (#3398)
--FILE--
<?php
try {
    echo match (0) {
        0 => throw new Exception(),
        default => 'd',
    };
} catch (Exception $e) {
    echo "caught\n";
}
--EXPECT--
caught
