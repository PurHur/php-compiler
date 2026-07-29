--TEST--
Language: file-level function decls early-bound across try/if (zend_compile.c, #24807)
--FILE--
<?php
try {
    echo hoist_try(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
function hoist_try() { return 'try_ok'; }

if (true) {
    echo hoist_if(), "\n";
}
function hoist_if() { return 'if_ok'; }

// Conditional decls must stay runtime-bound (not early-bound).
echo function_exists('never_declared') ? 'never_yes' : 'never_no', "\n";
if (false) {
    function never_declared() { return 'no'; }
}
echo function_exists('never_declared') ? 'never_yes' : 'never_no', "\n";
?>
--EXPECT--
try_ok
if_ok
never_no
never_no
