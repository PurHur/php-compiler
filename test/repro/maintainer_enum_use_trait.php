<?php
trait T {
    public function m(): void {
        echo "trait\n";
    }
}
enum E: int {
    use T;
    case A = 1;
}
E::A->m();
