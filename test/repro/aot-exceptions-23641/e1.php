<?php
echo "BEFORE\n";
try { throw new LogicException("boom"); }
catch (LogicException $e) { echo "caught\n"; }
echo "AFTER\n";
