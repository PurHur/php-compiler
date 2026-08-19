<?php
function f(): void {
    $o = new stdClass();
    $s = 'a';
    echo ($o == $s) ? "eq\n" : "neq\n";
}
f();
