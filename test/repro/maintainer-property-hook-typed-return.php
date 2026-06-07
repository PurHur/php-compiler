<?php

declare(strict_types=1);

class C
{
    public int $x { get => 'not int'; }
}

try {
    $v = (new C())->x;
    var_dump($v);
} catch (TypeError $e) {
    echo 'get TypeError', "\n";
}
