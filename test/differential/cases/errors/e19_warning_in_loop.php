<?php
// @differential-skip-aot: AOT omits stdout Warning: display_errors copy (#36383)
function w($i) {
    trigger_error('w'.$i, E_USER_WARNING);
}
for ($i = 0; $i < 2; $i++) {
    w($i);
}
echo "done\n";
