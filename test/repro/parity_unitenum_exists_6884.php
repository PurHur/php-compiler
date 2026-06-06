<?php
enum Pure { case A; }
enum Backed: string { case A = 'x'; }

foreach (['unitenum_exists', 'enum_exists'] as $fn) {
    echo $fn, '(Pure)=', var_export($fn('Pure'), true), "\n";
    echo $fn, '(Backed)=', var_export($fn('Backed'), true), "\n";
}
