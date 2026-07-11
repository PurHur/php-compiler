<?php
class C {
    public (private(set)) int $x = 1;
    public function bump(): void {
        $this->x++;
    }
}
$c = new C();
$c->bump();
echo $c->x, "\n";
try {
    $c->x = 5;
} catch (Error $e) {
    echo "caught: ", $e->getMessage(), "\n";
}
