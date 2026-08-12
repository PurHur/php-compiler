<?php

/** Repro #30563 — XMLReader::XML/open(null) weak: Deprecated + ValueError empty. */
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $msg): bool {
    if (E_DEPRECATED === $no) {
        echo "DEP:{$msg}\n";

        return true;
    }
    echo "E{$no}:{$msg}\n";

    return true;
});

try {
    XMLReader::XML(null);
    echo "XML:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}

try {
    $r = new XMLReader();
    $r->open(null);
    echo "open:no-error\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
