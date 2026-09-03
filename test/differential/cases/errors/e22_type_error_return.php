<?php
// @differential-skip-aot: AOT segfault rc=139 on typed return TypeError (#36383)
function ret_int(): int {
    return 'nope';
}
echo ret_int();
