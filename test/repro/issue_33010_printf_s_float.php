<?php
// #33010 — printf/sprintf("%s", float) must stringify, not pass double to libc %s
printf("%s\n", 1.5);
echo sprintf("%s\n", 1.5);
printf("%s\n", 7);
echo sprintf("%s\n", PHP_INT_MAX + 1.0);
printf("%.1f\n", 1.5);
