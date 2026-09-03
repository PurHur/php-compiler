<?php
// @differential-skip-aot: AOT omits stdout Warning: display_errors copy (#36383)
trigger_error('w', E_USER_WARNING);
echo "after\n";
