<?php
// @differential-skip-aot: AOT omits stdout Notice: display_errors copy (#36383)
trigger_error('n', E_USER_NOTICE);
echo "after\n";
