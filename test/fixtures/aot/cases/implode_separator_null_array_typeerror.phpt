--TEST--
AOT: implode(",", null) — Arg #1 ($array) TypeError abort (#19566, ext/standard/string.c)
--FILE--
<?php
implode(",", null);
--EXPECT--
--EXPECT_EXIT--
134
