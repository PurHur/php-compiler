<?php
$o = new readonly class {
    public int $x = 1;
};
var_export($o->x);
