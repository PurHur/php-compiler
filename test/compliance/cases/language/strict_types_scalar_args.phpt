--TEST--
Cross-file caller strict_types controls scalar parameter coercion (issue #9053)
--RUNFILE--
strict_types_scalar_args_run/entry.php
--EXPECT--
weak:int(1)
strict:TypeError
