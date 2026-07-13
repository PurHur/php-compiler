<?php
// Maintainer repro for #18579 — intdiv() catch/rethrow must deliver DivisionByZeroError.

echo "bare:\n";
try {
    intdiv(1, 0);
    echo "  no throw\n";
} catch (Throwable $e) {
    echo '  '.get_class($e).': '.$e->getMessage()."\n";
}

echo "rethrow:\n";
try {
    try {
        intdiv(1, 0);
    } catch (Throwable $e) {
        throw $e;
    }
} catch (Throwable $e) {
    echo '  '.get_class($e).': '.$e->getMessage()."\n";
}

echo "nested rethrow:\n";
try {
    try {
        try {
            intdiv(1, 0);
        } catch (Throwable $e) {
            throw $e;
        }
    } catch (Throwable $e) {
        throw $e;
    }
} catch (Throwable $e) {
    echo '  '.get_class($e).': '.$e->getMessage()."\n";
}
