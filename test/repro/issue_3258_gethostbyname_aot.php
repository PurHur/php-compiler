<?php
// Repro #3258 — gethostbyname()/gethostbynamel() must match VM under thin AOT.
echo gethostbyname('localhost'), "\n";
$h = 'localhost';
echo gethostbyname($h), "\n";
$ips = gethostbynamel('localhost');
echo count($ips), "\n";
echo $ips[0], "\n";
