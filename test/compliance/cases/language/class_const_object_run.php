<?php

class C {
    public const X = new stdClass();
}

$o = C::X;
echo $o instanceof stdClass ? '1' : '0';
echo "\n";
echo C::X === C::X ? '1' : '0';
echo "\n";
