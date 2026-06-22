<?php
enum E: string {
    case A = 'a';
}

$from = E::from(...);
echo 'stored ', $from('a')->name, "\n";

echo 'inline ', (E::from(...))('a')->name, "\n";

var_export((E::tryFrom(...))('z'));
