<?php
echo sprintf("%'*20s", 'x'), "\n";
echo sprintf("%'*10d", 7), "\n";
echo vsprintf("%'*8s", ['x']), "\n";
echo sprintf("%1$'*10s", 'x'), "\n";
echo sprintf("%-'*10s", 'x'), "\n";
