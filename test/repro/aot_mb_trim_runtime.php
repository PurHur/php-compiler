<?php
declare(strict_types=1);

function spaced(): string {
    return "  hi\t";
}

function nbsp_utf8(): string {
    return "\u{00A0}x\u{00A0}";
}

echo mb_trim(spaced()), "\n";
echo mb_ltrim(spaced()), "\n";
echo mb_rtrim(spaced()), "\n";
echo mb_trim(nbsp_utf8()), "\n";
echo mb_trim(spaced(), ' ', 'UTF-8'), "\n";
echo mb_trim(spaced(), '', 'UTF-8'), "\n";
