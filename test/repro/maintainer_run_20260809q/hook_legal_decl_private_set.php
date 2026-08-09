<?php
class C {
    public private(set) string $x {
        get => $this->x;
        set {
            $this->x = $value;
        }
    }
    public function setFromInside(string $v): void {
        $this->x = $v;
    }
}
$c = new C();
$c->setFromInside('ok');
echo $c->x, "\n";
try {
    $c->x = 'bad';
    echo "no-error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
