<?php
// AOT-runnable repro #23335 — Zend stub named params (no Reflection; separate AOT case-fold gap)
echo strcmp(string1: 'a', string2: 'b'), "\n";
echo strcasecmp(string1: 'A', string2: 'a'), "\n";
echo strncmp(string1: 'abc', string2: 'abd', length: 2), "\n";
echo strncasecmp(string1: 'AbC', string2: 'abd', length: 2), "\n";
