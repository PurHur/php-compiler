<?php
function check($value, $label) {
    try {
        throw $value;
    } catch (TypeError $e) {
        echo $label, ': TypeError: ', $e->getMessage(), "\n";
    } catch (Error $e) {
        echo $label, ': Error: ', $e->getMessage(), "\n";
    }
}

enum E: int { case A = 1; }
check(E::A, 'enum');
check('x', 'string');
check(1, 'int');
