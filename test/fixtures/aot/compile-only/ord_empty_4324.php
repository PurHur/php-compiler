<?php
// Compile-only (#4324); AOT runtime try/catch for pending ValueError from ord() pending (#4034).
try {
    ord('');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
