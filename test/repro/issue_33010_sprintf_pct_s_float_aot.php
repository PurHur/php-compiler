<?php
// Repro #33010 — AOT printf/sprintf("%s", float|int) must not SIGSEGV
declare(strict_types=1);

printf("%s\n", 1.5);
echo sprintf("%s\n", 1.5);
printf("%s\n", PHP_INT_MAX + 1.0);
printf("%.1f\n", 1.5);
printf("%s\n", 42);
