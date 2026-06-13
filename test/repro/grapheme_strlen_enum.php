<?php
enum Es: string { case A = 'á'; }
try {
    grapheme_strlen(Es::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
