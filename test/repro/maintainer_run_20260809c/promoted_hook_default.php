<?php
class C {
    public function __construct(
        public int $x {
            get => $this->x * 2;
            set {
                $this->x = $value + 1;
            }
        } = 1
    ) {}
}
$c = new C();
echo $c->x, "\n";
echo "ACCEPTED\n";
