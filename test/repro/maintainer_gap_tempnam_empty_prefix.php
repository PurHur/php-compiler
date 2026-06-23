<?php

$p = tempnam(sys_get_temp_dir(), '');
var_dump(is_string($p));
if (is_string($p)) {
    @unlink($p);
}
