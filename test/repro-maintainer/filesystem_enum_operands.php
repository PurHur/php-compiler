<?php
enum E: int { case A = 1; }
foreach (['chown', 'lchown', 'chgrp', 'lchgrp'] as $fn) {
    try { $fn('/tmp', E::A); } catch (Throwable $e) {
        echo $fn, ': ', get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try { chmod('/tmp', E::A); } catch (Throwable $e) {
    echo 'chmod: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try { chown(E::A, 0); } catch (Throwable $e) {
    echo 'chown_path: ', get_class($e), ': ', $e->getMessage(), "\n";
}
