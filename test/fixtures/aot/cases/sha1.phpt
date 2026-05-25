--TEST--
AOT sha1() digest (issue #2160)
--FILE--
<?php
echo sha1('csrf-token'), "\n";
--EXPECT--
7c0b01e017737b2ee7eeaaa8a80e5247595cfc4e
