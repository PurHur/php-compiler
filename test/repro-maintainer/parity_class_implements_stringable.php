<?php

class S {
    public function __toString(): string
    {
        return 'x';
    }
}

$s = new S();
echo in_array('Stringable', class_implements($s), true) ? "implements_yes\n" : "implements_no\n";
echo is_a($s, Stringable::class, true) ? "is_a_yes\n" : "is_a_no\n";
