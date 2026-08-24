<?php
declare(strict_types=1);

function bad_utf8(): string {
    return "a\x80b";
}

echo bin2hex(mb_scrub(bad_utf8())), "\n";
echo bin2hex(mb_scrub(bad_utf8(), 'ASCII')), "\n";
echo bin2hex(mb_scrub(bad_utf8(), '8bit')), "\n";
echo bin2hex(mb_scrub('hello')), "\n";
