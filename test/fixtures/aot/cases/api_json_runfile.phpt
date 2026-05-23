--TEST--
AOT: RUNFILE examples/004-ApiJson (shipped JSON API on disk)
--RUNFILE--
../../../../examples/004-ApiJson/example.php
--EXPECT--
Status: 200
Content-Type: application/json

{"ok":true,"service":"php-compiler"}
--EXPECT_EXIT--
0
