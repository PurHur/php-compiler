<?php
// lchown()/lchgrp() null path — warning must name callee (issue #18766)
@lchown(null, 0);
$err = error_get_last();
echo 'lchown=', ($err !== null ? $err['message'] : 'no_error'), PHP_EOL;

@lchgrp(null, 0);
$err = error_get_last();
echo 'lchgrp=', ($err !== null ? $err['message'] : 'no_error'), PHP_EOL;
