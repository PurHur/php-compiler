<?php
/**
 * #29319 — fmod/fdiv/hypot/atan2(null) soft-null DEP+coerce under PROFILE=8.4
 * (Zend 8.4; reverts wrong TypeError claim from #24198).
 *
 * Sequential calls (not foreach-of-null rows) so AOT binaries can run the same script.
 */
error_reporting(E_ALL);

$result = fmod(5.0, null);
echo 'ok:fmod:5.0,NULL=', is_nan($result) ? 'NAN' : var_export($result, true), "\n";

$result = fmod(null, 2.0);
echo 'ok:fmod:NULL,2.0=', var_export($result, true), "\n";

$result = fdiv(5.0, null);
echo 'ok:fdiv:5.0,NULL=', var_export($result, true), "\n";

$result = fdiv(null, 2.0);
echo 'ok:fdiv:NULL,2.0=', var_export($result, true), "\n";

$result = hypot(null, 3.0);
echo 'ok:hypot:NULL,3.0=', var_export($result, true), "\n";

$result = atan2(null, 1.0);
echo 'ok:atan2:NULL,1.0=', var_export($result, true), "\n";
