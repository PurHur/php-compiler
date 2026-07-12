<?php

declare(strict_types=1);

function probe($a, $b = null)
{
    echo 'a=', var_export($a, true), ' argc=', func_num_args(), PHP_EOL;
}

probe(false, new stdClass());
