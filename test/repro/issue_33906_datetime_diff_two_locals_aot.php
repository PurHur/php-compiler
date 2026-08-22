<?php
// re-#27309 — two DateTime locals; AOT must keep construct stamps for diff.
$a = new DateTime('2024-01-01');
$b = new DateTime('2024-01-10');
echo $a->diff($b)->days, "\n";
