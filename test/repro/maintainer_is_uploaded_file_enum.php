<?php
enum E: string { case A = '/tmp/x'; }
try {
    is_uploaded_file(E::A);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
