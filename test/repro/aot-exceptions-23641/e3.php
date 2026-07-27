<?php
echo "BEFORE\n";
try { echo "in_try\n"; }
catch (LogicException $e) { echo "caught\n"; }
echo "AFTER\n";
