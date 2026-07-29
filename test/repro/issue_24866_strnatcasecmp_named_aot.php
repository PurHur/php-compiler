<?php
/**
 * #24866 AOT — strnatcasecmp/strnatcmp Zend stub named params resolve.
 * Natural-compare return values under AOT are a pre-existing gap; assert
 * named and positional agree so the wiring (not the compare) is guarded.
 */
$named = strnatcasecmp(string1: 'a', string2: 'b');
$pos = strnatcasecmp('a', 'b');
echo (int) ($named === $pos), "\n";
$named2 = strnatcmp(string1: 'img2', string2: 'img10');
$pos2 = strnatcmp('img2', 'img10');
echo (int) ($named2 === $pos2), "\n";
