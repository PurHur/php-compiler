<?php
class C {
    public function __get($n) { echo "GET:$n\n"; return null; }
    public function __set($n, $v) { echo "SET:$n=$v\n"; }
}
$o = new C;
$o->x ??= 'fallback';
echo "done\n";
