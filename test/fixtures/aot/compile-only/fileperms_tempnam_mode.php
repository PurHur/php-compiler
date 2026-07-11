<?php
// Compile-only (#14055): tempnam() private file mode + fileperms() lowering.
declare(strict_types=1);

$f = tempnam(sys_get_temp_dir(), 'phpc');
$tail = substr(sprintf('%o', fileperms($f)), -3);
@unlink($f);
echo $tail, "\n";
