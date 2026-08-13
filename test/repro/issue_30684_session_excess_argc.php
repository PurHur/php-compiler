<?php
/**
 * session_id/name/module_name excess argc + session_commit alias ACE (#30684).
 * php-src: ext/session/session.c
 */
try {
    session_id(null, 1);
    echo "session_id:OK\n";
} catch (ArgumentCountError $e) {
    echo 'session_id:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'session_id:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    session_name(null, 1);
    echo "session_name:OK\n";
} catch (ArgumentCountError $e) {
    echo 'session_name:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'session_name:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    session_module_name(null, 1);
    echo "session_module_name:OK\n";
} catch (ArgumentCountError $e) {
    echo 'session_module_name:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'session_module_name:', get_class($e), ':', $e->getMessage(), "\n";
}

try {
    session_commit(1);
    echo "session_commit:OK\n";
} catch (ArgumentCountError $e) {
    echo 'session_commit:ArgumentCountError:', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo 'session_commit:', get_class($e), ':', $e->getMessage(), "\n";
}

echo 'ok_id:', session_id(), "\n";
echo 'ok_name:', session_name(), "\n";
echo 'ok_module:', session_module_name(), "\n";
