<?php

declare(strict_types=1);

// Repro #17064 — header() CR/LF must warn and continue (ext/standard/head.c).
header("X: a\r\nInjected: yes");
echo "ok\n";
