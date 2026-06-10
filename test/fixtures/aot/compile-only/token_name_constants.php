<?php

declare(strict_types=1);

// Compile-only (#3171): token_name() must lower T_* constants for AOT.
echo token_name(T_FUNCTION), "\n";
echo token_name(T_ECHO), "\n";
echo token_name(T_VARIABLE), "\n";
