<?php

declare(strict_types=1);

$s = "\xC3\x28";
var_dump(json_encode($s));
var_dump(json_last_error(), json_last_error_msg());
