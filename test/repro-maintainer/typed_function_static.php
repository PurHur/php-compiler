<?php
declare(strict_types=1);

function counter(): void {
    static int $n = 0;
    $n++;
    echo $n, "\n";
}
counter();
counter();
