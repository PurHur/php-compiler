<?php
echo password_verify(password: 'x', hash: 'y') ? "verify_named=1\n" : "verify_named=0\n";
echo is_array(password_algos()) ? "algos_ok\n" : "algos_bad\n";
