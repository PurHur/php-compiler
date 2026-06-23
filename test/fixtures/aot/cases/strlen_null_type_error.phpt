--TEST--
AOT: strlen() — null TypeError (#10910)
--FILE--
<?php
declare(strict_types=1);
strlen(null);
--EXPECT--
--EXPECT_EXIT--
134
