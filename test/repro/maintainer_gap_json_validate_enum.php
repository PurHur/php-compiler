<?php
enum E: string { case A = 'x'; }
try {
    json_validate(E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
