--TEST--
Language: global const array push — compile-time fatal (#6935)
--FILE--
<?php
const ARR = [];
ARR[] = 1;
var_dump(ARR);
--EXPECT_EXIT--
255
