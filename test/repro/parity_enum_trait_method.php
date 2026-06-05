<?php
declare(strict_types=1);

trait T {
    public function tag(): string { return 't'; }
}

enum E: int {
    case A = 1;
    use T;
}

echo E::A->tag(), "\n";
