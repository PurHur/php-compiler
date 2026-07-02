<?php

$r = @preg_replace('/test/e', 'x', 'test');
echo 'result=', var_export($r, true), ' last_error=', preg_last_error(), "\n";
if (null !== $r || 1 !== preg_last_error()) {
    exit(1);
}
