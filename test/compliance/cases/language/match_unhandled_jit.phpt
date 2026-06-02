--TEST--
JIT: match throws UnhandledMatchError when no arm matches (issue #4221)
--FILE--
<?php
try {
    echo match (2) {
        1 => 'a',
    }, "\n";
} catch (UnhandledMatchError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
UnhandledMatchError: Unhandled match case 2
