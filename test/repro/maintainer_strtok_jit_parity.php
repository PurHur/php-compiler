<?php
$s = "a,b,c";
echo strtok($s, ","), "\n";
echo strtok(","), "\n";
echo strtok(","), "\n";
echo var_export(strtok(","), true), "\n";
strtok("x:y", ":");
echo strtok(":"), "\n";
