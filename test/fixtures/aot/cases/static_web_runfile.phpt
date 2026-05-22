--TEST--
AOT: RUNFILE examples/002-StaticWeb (shipped static page on disk)
--RUNFILE--
../../../../examples/002-StaticWeb/example.php
--EXPECTREGEX--
(?s)^Content-Type: text\/html; charset=UTF-8\n.*<h1>Hello World<\/h1>
--EXPECT_EXIT--
0
