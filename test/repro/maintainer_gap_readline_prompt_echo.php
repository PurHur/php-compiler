<?php

declare(strict_types=1);

// Maintainer gap #12301 — readline() must not echo prompt on non-interactive stdin (readline.c).

readline('phpc-prompt> ');
echo "ok\n";
