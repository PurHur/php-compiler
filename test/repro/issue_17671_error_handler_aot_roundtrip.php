<?php

declare(strict_types=1);

function h($e, $s, $f, $l)
{
    return true;
}

set_error_handler('h');
echo get_error_handler() ?? 'null', "\n";
