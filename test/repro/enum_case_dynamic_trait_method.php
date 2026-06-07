<?php
declare(strict_types=1);

trait T {
    public function m(): string { return 't'; }
}

enum E: string {
    case A = 'a';
    use T;
}

$method = 'm';
echo E::A->$method(), "\n";
