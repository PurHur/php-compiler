--TEST--
stdlib sprintf/printf family print INF for -INF (issue #23607, ext/standard/formatted_print.c)
--FILE--
<?php
echo sprintf('%f', -INF), "\n";
echo sprintf('%F', -INF), "\n";
echo sprintf('%e', -INF), "\n";
echo sprintf('%E', -INF), "\n";
echo sprintf('%g', -INF), "\n";
echo sprintf('%G', -INF), "\n";
echo sprintf('%+f', -INF), "\n";
echo sprintf('%+f', INF), "\n";
echo vsprintf('%F', [-INF]), "\n";
echo sprintf('%f', NAN), "\n";
--EXPECT--
INF
INF
INF
INF
INF
INF
INF
INF
INF
NaN
