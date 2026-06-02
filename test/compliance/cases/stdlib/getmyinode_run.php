<?php
$i = getmyinode();
echo $i !== false && $i > 0 ? "inode\n" : "bad\n";
echo getmyinode() === $i ? "stable\n" : "bad\n";
