--TEST--
AOT parse_url() missing component returns false
--FILE--
<?php
echo parse_url('http://', 1) === false ? 'false' : 'not-false', "\n";
echo parse_url('http://example.com', 2) === false ? 'false' : 'not-false', "\n";
--EXPECT--
false
false
