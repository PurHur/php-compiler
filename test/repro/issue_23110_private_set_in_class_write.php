<?php
// Issue #23110 — public private(set) in-class writes succeed; external deny uses private(set) wording.
class U
{
    public private(set) int $n = 0;

    public function bump(): void
    {
        $this->n = $this->n + 1;
    }
}

$u = new U();
$u->bump();
echo $u->n, "\n";

class T
{
    public private(set) string $x = 'a';
    public protected(set) string $y = 'b';
}

$t = new T();
try {
    $t->x = 'z';
    echo "x_ok\n";
} catch (Error $e) {
    echo 'x:', $e->getMessage(), "\n";
}
try {
    $t->y = 'z';
    echo "y_ok\n";
} catch (Error $e) {
    echo 'y:', $e->getMessage(), "\n";
}
