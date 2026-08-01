<?php
// Historical #6887 sample — `abstract enum` is now a parse fatal (#26519).
abstract enum E: int {
    case A = 1;
    abstract public function label(): string;
}
enum F: int implements E {
    case A = 1;
    public function label(): string { return 'A'; }
}
echo F::A->label(), "\n";
