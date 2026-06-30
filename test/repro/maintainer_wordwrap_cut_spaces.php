<?php

declare(strict_types=1);

// Issue #10195 — wordwrap() cut=true must break at spaces when possible (ext/standard/string.c).
echo wordwrap('hello world test string here', 5, "\n", true);
