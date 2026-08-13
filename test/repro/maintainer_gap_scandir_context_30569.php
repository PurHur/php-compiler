<?php
try {
    $r = scandir('.', SCANDIR_SORT_ASCENDING, null);
    echo 'ok count=', is_array($r) ? count($r) : 'false', "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    scandir('.', SCANDIR_SORT_ASCENDING, 1);
    echo "CTX_NO_THROW\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    scandir('.', SCANDIR_SORT_ASCENDING, null, 'extra');
    echo "NO_THROW\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
