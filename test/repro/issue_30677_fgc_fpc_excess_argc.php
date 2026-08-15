<?php

/**
 * Repro #30677 — file_get_contents/file_put_contents excess argc uses Zend "at most" wording.
 * php-src: ext/standard/file.c
 */
try {
    file_get_contents('/etc/hosts', false, null, 0, null, 1);
    echo "fgc excess:NO_THROW\n";
} catch (Throwable $e) {
    echo 'fgc excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    file_get_contents();
    echo "fgc missing:NO_THROW\n";
} catch (Throwable $e) {
    echo 'fgc missing:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    file_put_contents('/tmp/t', 'a', 0, null, 1);
    echo "fpc excess:NO_THROW\n";
} catch (Throwable $e) {
    echo 'fpc excess:', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    file_put_contents('/tmp/t');
    echo "fpc missing:NO_THROW\n";
} catch (Throwable $e) {
    echo 'fpc missing:', get_class($e), ':', $e->getMessage(), "\n";
}
