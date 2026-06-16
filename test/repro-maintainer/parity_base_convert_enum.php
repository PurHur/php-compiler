<?php
declare(strict_types=1);

enum Es: string { case A = 'ff'; }

try {
    echo base_convert(Es::A, 16, 10), "\n";
} catch (Throwable $t) {
    echo $t::class, ': ', $t->getMessage(), "\n";
}
