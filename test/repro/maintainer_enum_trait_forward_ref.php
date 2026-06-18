<?php
enum E: string {
    case A = 'a';
    use T;
    public function hi(): string { return 'hi'; }
}
trait T {}
var_dump(E::A->hi());
