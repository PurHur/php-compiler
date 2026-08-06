<?php
/**
 * AOT getimagesize() on 1×1 GIF tempfile — must print 1x1 (not segfault) (#27291).
 */
$f = tempnam(sys_get_temp_dir(), 'gif');
file_put_contents($f, base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'));
$i = @getimagesize($f);
echo is_array($i) ? ($i[0].'x'.$i[1]) : 'fail', "\n";
@unlink($f);
