--TEST--
Language: exit(status:) source pragma enables 8.4 profile without CLI env (#17681)
--FILE--
<?php
declare(strict_types=1);
// php-compiler-language-profile=8.4
exit(status: 3);
--EXPECT_EXIT--
3
