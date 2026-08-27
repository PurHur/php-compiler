--TEST--
Collator::PRIMARY ClassConstFetch seeds for thin AOT (#35379)
--FILE--
<?php
echo 'PRIMARY=', Collator::PRIMARY, "\n";
echo 'SECONDARY=', Collator::SECONDARY, "\n";
echo 'SORT_REGULAR=', Collator::SORT_REGULAR, "\n";
--EXPECT--
PRIMARY=0
SECONDARY=1
SORT_REGULAR=0
