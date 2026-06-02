--TEST--
match expression throws UnhandledMatchError when no arm matches (issue #4221)
--FILE--
<?php
try {
    echo match (2) {
        1 => 'a',
    }, "\n";
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo match (99) {
    1 => 'one',
    default => 'other',
}, "\n";
--EXPECT--
UnhandledMatchError: Unhandled match case 2
other
