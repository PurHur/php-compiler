<?php

declare(strict_types=1);

preg_match('/(.*)\0(.*)/', "a\0b", $m);
var_export($m);
echo "\n";
