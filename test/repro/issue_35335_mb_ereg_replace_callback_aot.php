<?php

declare(strict_types=1);

/** #35335 — mb_ereg_replace_callback named fn + preg /a+/ thin AOT repro. */
function up(array $m): string
{
    return strtoupper($m[0]);
}

echo mb_ereg_replace_callback('a+', 'up', 'xaaay'), "\n";
echo preg_replace_callback('/a+/', 'up', 'xaaay'), "\n";
