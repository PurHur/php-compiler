<?php
function f($a, $b)
{
    return $a + $b;
}
echo 3 === call_user_func('f', 1, 2) ? '3' : 'x';
echo '|';
echo 3 === call_user_func('f', ...[1, 2]) ? '3' : 'x';
