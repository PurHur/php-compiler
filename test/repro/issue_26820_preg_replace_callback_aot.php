<?php
// Issue #26820: AOT preg_replace_callback(string fn) must match Zend/VM/JIT.
function double_digit(array $m): string
{
    return (string) ((int) $m[0] * 2);
}
echo preg_replace_callback('/\d/', 'double_digit', 'a1b2'), "\n";
