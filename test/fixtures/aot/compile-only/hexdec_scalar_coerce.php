<?php
declare(strict_types=1);
// Compile-only (#4217): hexdec/bindec/octdec scalar-to-string coercion lowering.
echo hexdec(1.5), "\n";
echo octdec(7.0), "\n";
echo bindec(1010), "\n";
