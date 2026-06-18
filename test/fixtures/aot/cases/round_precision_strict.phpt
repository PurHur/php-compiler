--TEST--
AOT round() precision — strict_types TypeError on float (#9482)
--FILE--
<?php
declare(strict_types=1);
round(1.5, 0.9);
--EXPECT--
--EXPECT_EXIT--
255
