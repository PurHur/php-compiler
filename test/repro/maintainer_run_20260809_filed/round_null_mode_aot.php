<?php
/**
 * #29384 AOT probe — null $mode soft-null DEP then abort (ValueError path, not TypeError).
 * Catchable AOT try/catch for this helper is deferred (peer substr_count #29421).
 */
round(1.5, 0, null);
echo "unreachable\n";
