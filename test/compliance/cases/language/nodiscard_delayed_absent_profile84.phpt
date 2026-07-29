--TEST--
Language: NoDiscard/DelayedTargetValidation absent on PROFILE=8.4 — Zend 8.5-only (#24946)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo "NoDiscard=", class_exists("NoDiscard", false) ? "1" : "0", "\n";
echo "Delayed=", class_exists("DelayedTargetValidation", false) ? "1" : "0", "\n";
echo "done\n";
--EXPECT--
NoDiscard=0
Delayed=0
done
