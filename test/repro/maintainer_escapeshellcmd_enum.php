<?php
declare(strict_types=1);

enum Es: string { case A = 'x'; }
try {
    echo escapeshellcmd(Es::A);
} catch (Throwable $e) {
    echo get_class($e).': '.$e->getMessage();
}
