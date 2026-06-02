<?php
class R {
    public function __destruct() {
        echo "dtor\n";
    }
}
$o = new R();
unset($o);
