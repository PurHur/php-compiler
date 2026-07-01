<?php

declare(strict_types=1);

class Reader {
    private(set) string $label = 'ok';

    public function touch(): void
    {
        $this->label = 'in-class';
    }
}

$r = new Reader();
echo $r->label, "\n";
$r->touch();
echo $r->label, "\n";
try {
    $r->label = 'external';
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
