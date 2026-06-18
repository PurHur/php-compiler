<?php
// Compile-only (#9058): sprintf() sign/alternate flags must lower for AOT.
echo sprintf('%+d', 5), "\n";
echo sprintf('%#x', 255), "\n";
echo sprintf('%#X', 255), "\n";
echo sprintf('%#o', 8), "\n";
