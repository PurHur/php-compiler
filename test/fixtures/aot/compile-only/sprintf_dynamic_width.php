<?php
// Compile-only (#9069): sprintf() dynamic width must lower for AOT.
echo sprintf('%*d', 5, 1), "\n";
echo sprintf('%0*d', 5, 1), "\n";
