<?php
// Inline mk() loop — same leak shape as issue_36215_container_leak.php without a function call.

for ($i = 0; $i < 50000; $i++) {
    $a = ['k'.$i => str_repeat('x', 100), 'n' => [$i, $i + 1]];
}
echo "done\n";
