<?php
enum E: int { case A = 1; }
var_dump(E::A instanceof E);
var_dump(is_a(E::A, E::class));
foreach (E::cases() as $case) {
    var_dump($case instanceof E);
    var_dump(is_a($case, E::class));
}
