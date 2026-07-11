--TEST--
stdlib grapheme_strlen()/grapheme_strimwidth() — call fatal on PHP 8.2 reference profile (#17105, ext/intl/grapheme)
--FILE--
<?php
declare(strict_types=1);

grapheme_strlen('ab');
--EXPECTF--
%ACall to undefined function grapheme_strlen()%A
--EXPECT_EXIT--
255
