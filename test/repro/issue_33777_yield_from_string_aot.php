<?php
// AOT: yield from string must Error like Zend (not abort compile with TypeError).
// Thin AOT ErrorRaise may surface as uncaught Error; message must still match.
function gen() {
    yield from 'hi';
}
try {
    foreach (gen() as $v) {
        echo $v;
    }
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
