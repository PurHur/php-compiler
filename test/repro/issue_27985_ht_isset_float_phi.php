<?php
/**
 * #27985 — AOT must module-verify when float→int dim isset uses
 * floatToLongWithPrecisionWarning (PHI predecessor = insert block after helper).
 */
$a = [10, 20];
$k = 1.0;
echo 'isset:', isset($a[$k]) ? '1' : '0', "\n";
echo 'hello', "\n";
