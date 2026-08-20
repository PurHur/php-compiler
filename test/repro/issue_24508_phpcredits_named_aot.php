<?php
// #24508 AOT: named flags: must bind; phpcredits returns true (ext/standard/info.c RETURN_TRUE)
ob_start();
$ok = phpcredits(flags: CREDITS_GENERAL);
ob_end_clean();
var_dump($ok);
