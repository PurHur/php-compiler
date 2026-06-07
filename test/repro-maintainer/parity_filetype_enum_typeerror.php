<?php
enum E: string { case X = 'x'; }
try {
    filetype(E::X);
    echo "no throw\n";
} catch (TypeError $e) {
    echo "TypeError\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
