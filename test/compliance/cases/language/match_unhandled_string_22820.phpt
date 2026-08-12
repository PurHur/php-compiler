--TEST--
match unhandled string subject throws UnhandledMatchError (issue #22820; re-#13955; message form #23664)
--FILE--
<?php
try {
    match("x") { "a" => 1 };
    echo "no throw\n";
} catch (UnhandledMatchError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    echo (get_parent_class($e) === 'Error') ? "extends Error\n" : "bad parent\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
UnhandledMatchError: Unhandled match case '...'
extends Error
