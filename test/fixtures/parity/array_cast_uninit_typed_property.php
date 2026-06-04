<?php
class C {
    public int $x;
}
$c = new C();
array_key_exists('x', (array) $c);
echo "ok\n";
