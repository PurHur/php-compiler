<?php
// Issue #18733 — urlencode(null) TypeError (ext/standard/url.c).
try {
    urlencode(null);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
