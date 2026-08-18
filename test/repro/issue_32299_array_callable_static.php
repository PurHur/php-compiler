<?php
class C
{
    public static function m()
    {
        echo 'U';
    }
}
$cb = ['C', 'm'];
$cb();
echo '|';
$s = 'C::m';
$s();
echo '|';
(new C)->m();
