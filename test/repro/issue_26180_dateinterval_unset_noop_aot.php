<?php
// AOT probe for #26180 — DateInterval property fetch under AOT segfaults (pre-existing);
// verify living unset lowers as a no-op and the binary runs.
$i = new DateInterval('P2Y3M4DT5H6M7S');
unset($i->d);
unset($i->y);
unset($i->m);
unset($i->h);
unset($i->i);
unset($i->s);
unset($i->invert);
echo "unset_ok\n";
