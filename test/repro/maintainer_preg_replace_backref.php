<?php

declare(strict_types=1);

// Issue #9599 — preg_replace() numeric capture backreferences (ext/pcre/php_pcre.c).
echo preg_replace('/([0-9]+)/', '[$1]', 'x12y'), "\n";
echo preg_replace('/(\d)/', '${1}x', 'a9b'), "\n";
echo preg_replace('/(.)(.)/', '$2$1', 'ab'), "\n";
