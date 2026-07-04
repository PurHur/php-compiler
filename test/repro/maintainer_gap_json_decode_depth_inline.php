<?php
declare(strict_types=1);

var_export(json_decode('{"a":{"b":1}}', true, 1));
echo " depth-err=", json_last_error(), "\n";
