<?php
// Repro #21593 — intdiv(null, 2) soft-null under PHP_COMPILER_PROFILE=8.4
echo intdiv(null, 2), PHP_EOL;
