<?php
set_error_handler(function ($n, $s) {
    if ($n === E_DEPRECATED) {
        echo "DEP\n";
        return true;
    }
    return false;
});

// htmlspecialchars
try {
    $r = htmlspecialchars('a', null);
    echo "htmlspecialchars: "; var_export($r); echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// htmlentities
try {
    $r = htmlentities('a', null);
    echo "htmlentities: "; var_export($r); echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// htmlspecialchars_decode
try {
    $r = htmlspecialchars_decode('&amp;', null);
    echo "htmlspecialchars_decode: "; var_export($r); echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}

// html_entity_decode
try {
    $r = html_entity_decode('&amp;', null);
    echo "html_entity_decode: "; var_export($r); echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ": ", $e->getMessage(), "\n";
}
