<?php

declare(strict_types=1);

// AOT: trigger_error must print under default error_reporting after #35563 gate fix.
trigger_error('hello warning', E_USER_WARNING);
echo "done\n";
