<?php
enum E: int {
    case A = 1;
    public static function label(): string { return 'ok'; }
}
echo (E::A)::label(), "\n";
