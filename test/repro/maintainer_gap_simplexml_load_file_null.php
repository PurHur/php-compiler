<?php
try {
    $r = @simplexml_load_file(null);
    var_export($r);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
