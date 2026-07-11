--TEST--
chown()/chgrp()/lchown()/lchgrp() null user/group coerces to uid/gid 0 (#14673, Z_PARAM_STR_OR_LONG)
--FILE--
<?php
$path = sys_get_temp_dir();
echo 'chown=', var_export(@chown($path, null), true), "\n";
echo 'chgrp=', var_export(@chgrp($path, null), true), "\n";
echo 'lchown=', var_export(@lchown($path, null), true), "\n";
echo 'lchgrp=', var_export(@lchgrp($path, null), true), "\n";
--EXPECT--
chown=true
chgrp=true
lchown=true
lchgrp=true
