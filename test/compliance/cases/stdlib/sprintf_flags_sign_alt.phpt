--TEST--
stdlib sprintf() sign flag and # rejected per php-src formatted_print.c (#9058, #9701)
--FILE--
<?php
echo sprintf('%+d', 5), "\n";
try {
    echo sprintf('%#x', 255), "\n";
} catch (ValueError $e) {
    echo "ValueError\n";
    echo $e->getMessage(), "\n";
}
echo sprintf('% d', 5), "\n";
echo sprintf('%+d', -5), "\n";
--EXPECT--
+5
ValueError
Unknown format specifier "#"
5
-5
