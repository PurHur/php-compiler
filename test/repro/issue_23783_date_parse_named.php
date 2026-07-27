<?php
// Issue #23783 — date_parse/date_parse_from_format Zend stub named params.
$a = date_parse(datetime: '2020-01-01');
echo 'year:', (string) $a['year'], "\n";
$rf = new ReflectionFunction('date_parse');
$dpNames = [];
foreach ($rf->getParameters() as $p) {
    $dpNames[] = $p->getName();
}
echo 'dp_params:', implode(',', $dpNames), "\n";
$legacy = null;
try {
    date_parse(date: '2020-01-01');
    $legacy = 'date accepted';
} catch (Throwable $e) {
    $legacy = $e->getMessage();
}
echo $legacy, "\n";

$b = date_parse_from_format(format: 'Y-m-d', datetime: '2020-01-02');
echo 'year2:', (string) $b['year'], "\n";
$rf2 = new ReflectionFunction('date_parse_from_format');
$dpfNames = [];
foreach ($rf2->getParameters() as $p) {
    $dpfNames[] = $p->getName();
}
echo 'dpf_params:', implode(',', $dpfNames), "\n";
?>
