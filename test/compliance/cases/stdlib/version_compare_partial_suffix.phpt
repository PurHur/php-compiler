--TEST--
stdlib version_compare X.Y vs X.Y.Z-dev / special suffix (#23508)
--FILE--
<?php
// php-src ext/standard/versioning.c — shorter numeric prefix vs longer + suffix
echo version_compare('8.4', '8.4.0-dev'), ' ', version_compare('8.4.0-dev', '8.4'), "\n";
echo version_compare('1.0', '1.0.0-dev'), ' ', version_compare('1.0.0-dev', '1.0'), "\n";
echo version_compare('8.4.0', '8.4.0-dev'), ' ', version_compare('8.4.0-dev', '8.4.0'), "\n";
echo version_compare('2.0', '2.0.0-alpha'), ' ', version_compare('2.0.0-alpha', '2.0'), "\n";
echo version_compare('2.0', '2.0.0-beta'), ' ', version_compare('2.0.0-beta', '2.0'), "\n";
echo version_compare('2.0', '2.0.0RC1'), ' ', version_compare('2.0.0RC1', '2.0'), "\n";
echo version_compare('1.2.3', '1.2.3'), ' ', version_compare('1.2.3', '1.2.3'), "\n";
echo version_compare('8.4', '8.4.0-dev', '<') ? "lt\n" : "no\n";
echo version_compare('8.4.0-dev', '8.4', '>') ? "gt\n" : "no\n";
--EXPECT--
-1 1
-1 1
1 -1
-1 1
-1 1
-1 1
0 0
lt
gt
