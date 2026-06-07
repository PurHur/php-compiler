<?php
abstract enum E: int {
    case A = 1;
    abstract public function label(): string;
}
enum F: int implements E {
    case A = 1;
    public function label(): string { return 'A'; }
}
echo F::A->label(), "\n";
