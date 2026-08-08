<?php
/** #28819 SplFixedArray OOB under PROFILE>=8.4 */
$a = new SplFixedArray(1);
try { $a[5] = 1; } catch (Throwable $e) { echo "set:", get_class($e), "\n"; }
try { $a->offsetGet(5); } catch (Throwable $e) { echo "get:", get_class($e), "\n"; }
