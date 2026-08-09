<?php
class C {
    public $x {
        set(&$value) {
            $this->x = $value;
        }
    }
}
echo "accepted\n";
