<?php
$combo = pathinfo('/a/b.c', PATHINFO_DIRNAME | PATHINFO_EXTENSION);
echo $combo['dirname'], "\n";
echo $combo['extension'], "\n";
