<?php
class BackedGet {
    public $x = 1 {
        get => $this->x + 100;
    }
}
debug_zval_dump(new BackedGet);
