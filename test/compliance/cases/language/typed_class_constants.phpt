--TEST--
Language: typed class constants (PHP 8.3) — parse and evaluate (#30176)
--FILE--
<?php
class Config {
    const string NAME = "app";
    const int VERSION = 1;
    const float PI = 3.14;
    const bool ENABLED = true;
    const array ITEMS = [1, 2, 3];
}
echo Config::NAME . "\n";
echo Config::VERSION . "\n";
echo Config::PI . "\n";
echo (Config::ENABLED ? "yes" : "no") . "\n";
echo count(Config::ITEMS) . "\n";
--EXPECT--
app
1
3.14
yes
3
