--TEST--
AOT: RUNFILE examples/001-SimpleWeb (bin/compile.php on shipped example)
--RUNFILE--
../../../../examples/001-SimpleWeb/example.php
--GET--
name=RunfileGet
--EXPECTREGEX--
(?s)^Content-Type: text\/html; charset=UTF-8\n.*Hello RunfileGet.*form method="post"
--EXPECT_EXIT--
0
