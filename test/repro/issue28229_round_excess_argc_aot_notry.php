<?php
// #28229 AOT smoke — uncaught excess argc (try/catch AOT catchable is a wider ExceptionBridge gap).
round(1.5, 0, 1, true);
echo "ran\n";
