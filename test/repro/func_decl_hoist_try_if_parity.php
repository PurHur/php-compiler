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
