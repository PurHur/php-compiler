<?php

declare(strict_types=1);

function pick_int_or_true(bool $flag): int|true
{
    return $flag ? 1 : true;
}

echo pick_int_or_true(true) === true ? "true\n" : "1\n";
echo pick_int_or_true(false) === true ? "true\n" : "1\n";
