<?php
/**
 * #28425 — AOT runtime probe: readlink returns false / linkinfo returns -1 on missing path.
 */
$missing = @readlink('/no/such/file/28425');
echo 'readlink_missing=', (false === $missing) ? 'false' : gettype($missing), "\n";
$dev = @linkinfo('/no/such/file/28425');
echo 'linkinfo_missing=', (-1 === $dev) ? '-1' : var_export($dev, true), "\n";
