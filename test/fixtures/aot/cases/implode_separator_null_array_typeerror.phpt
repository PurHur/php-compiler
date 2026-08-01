--TEST--
AOT: implode(",", null) — Arg #1 ($array) TypeError fatal (#19566; exit 255 via ExceptionBridge)
--FILE--
<?php
implode(",", null);
--EXPECT--
--EXPECT_EXIT--
255
