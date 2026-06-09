<?php
echo strip_tags('<a><b>x</b></a>', ['a']), "\n";
echo strip_tags('<b>x</b><i>y</i>', ['b', 'i']), "\n";
