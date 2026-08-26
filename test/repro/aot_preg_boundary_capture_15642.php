<?php

declare(strict_types=1);

// AOT: preg_match capture with literal prefix + class group — tier-2 preg_capture (#15642).
preg_match("/b(oundary)=(\\w+)/", "boundary=x", $m);
echo $m[2] ?? "(none)", "\n";
