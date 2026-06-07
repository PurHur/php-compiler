<?php
declare(strict_types=1);

$callable = exit(...);
var_dump($callable instanceof Closure);

exit(status: 0);
