<?php
/**
 * #32806 — local string dim write must stay a string under AOT (regression from #32804).
 */
$s = 'abc';
$s[1] = 'Z';
echo $s, "\n";
