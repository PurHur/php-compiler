<?php
/** #35335 — mb_ereg_replace_callback AOT via ERE→PCRE + preg thin callback bridge. */
declare(strict_types=1);

function up(array $m): string
{
    return strtoupper($m[0]);
}

echo mb_ereg_replace_callback('a+', 'up', 'xaaay'), "\n";
echo preg_replace_callback('/a+/', 'up', 'xaaay'), "\n";
