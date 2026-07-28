--TEST--
stdlib tidy — not advertised on reference profile (#23955, ext/tidy)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('tidy') ? "fail ext\n" : "ok ext\n";
echo function_exists('tidy_parse_string') ? "fail tidy_parse_string\n" : "ok tidy_parse_string\n";
echo class_exists('tidy') ? "fail tidy\n" : "ok tidy\n";
echo class_exists('tidyNode') ? "fail tidyNode\n" : "ok tidyNode\n";
--EXPECT--
ok ext
ok tidy_parse_string
ok tidy
ok tidyNode
