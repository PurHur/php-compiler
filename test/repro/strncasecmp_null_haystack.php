<?php

declare(strict_types=1);

// #18700 — strncasecmp(null haystack) must coerce to "" and return -1 vs shorter needle.
echo strncasecmp(null, 'a', 1), "\n";
echo strncasecmp('', 'a', 1), "\n";
echo strncasecmp('a', null, 1), "\n";
echo strncasecmp('ab', 'ABC', 3), "\n";
