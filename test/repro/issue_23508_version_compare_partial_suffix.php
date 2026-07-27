<?php
// Repro #23508: X.Y vs X.Y.Z-dev must match php-src versioning.c
echo version_compare('8.4', '8.4.0-dev'), "\n";
echo version_compare('8.4.0-dev', '8.4'), "\n";
echo version_compare('1.0', '1.0.0-dev'), "\n";
echo version_compare('8.4.0', '8.4.0-dev'), "\n";
