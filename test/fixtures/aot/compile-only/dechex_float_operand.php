<?php
declare(strict_types=1);
// Compile-only (#4211): dechex/decbin/decoct Z_PARAM_LONG float truncation lowering.
echo dechex(1.9), "\n";
echo decbin(2.9), "\n";
echo decoct(7.9), "\n";
