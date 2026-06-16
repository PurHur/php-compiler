<?php
class T {
    public function m(): string {
        return 'm';
    }
}
$t = new T();
$f = $t->m(...);
echo $f();
