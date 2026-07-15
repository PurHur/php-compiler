--TEST--
JIT: lchown()/lchgrp() null path — warning names callee not chown()/chgrp() (#18766)
--FILE--
<?php
@lchown(null, 0);
$err = error_get_last();
echo ($err !== null ? $err['message'] : 'no_error')."\n";

@lchgrp(null, 0);
$err = error_get_last();
echo ($err !== null ? $err['message'] : 'no_error')."\n";
--EXPECT--
lchown(): No such file or directory
lchgrp(): No such file or directory
