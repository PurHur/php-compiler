--TEST--
AOT: mb_scrub() invalid-byte scrubbing (#6050)
--FILE--
<?php
echo function_exists('mb_scrub') ? 'yes' : 'no', "\n";
echo mb_scrub("\xFF", 'UTF-8'), "\n";
echo mb_scrub('café', 'UTF-8'), "\n";
--EXPECT--
yes
?
café
