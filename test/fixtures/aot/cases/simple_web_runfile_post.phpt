--TEST--
AOT: RUNFILE examples/001-SimpleWeb via $_REQUEST POST (runtime REQUEST_BODY)
--RUNFILE--
../../../../examples/001-SimpleWeb/example.php
--POST--
name=RunfilePost
--EXPECTREGEX--
(?s)^Content-Type: text\/html; charset=UTF-8\n.*Hello RunfilePost.*form method="post"
--EXPECT_EXIT--
0
