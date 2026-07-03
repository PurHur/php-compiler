<?php
declare(strict_types=1);
class C {
    private(set) string $p = 'x';
}
echo "fail: bare private(set) accepted\n";
