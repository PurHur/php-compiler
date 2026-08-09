<?php
declare(strict_types=1);

echo 'ext=', extension_loaded('mongodb') ? 'Y' : 'N', "\n";
foreach ([
    'MongoDB\\Driver\\Manager',
    'MongoDB\\Driver\\Query',
    'MongoDB\\Driver\\BulkWrite',
    'MongoDB\\Driver\\Cursor',
    'MongoDB\\Driver\\Command',
    'MongoDB\\Driver\\ReadPreference',
    'MongoDB\\Driver\\WriteConcern',
    'MongoDB\\Driver\\Session',
    'MongoDB\\Driver\\Server',
    'MongoDB\\BSON\\ObjectId',
    'MongoDB\\BSON\\UTCDateTime',
    'MongoDB\\BSON\\Binary',
    'MongoDB\\BSON\\Regex',
    'MongoDB\\BSON\\Decimal128',
    'MongoDB\\BSON\\Timestamp',
] as $c) {
    echo $c, '=', class_exists($c, false) ? 'Y' : 'N', "\n";
}

$id = new MongoDB\BSON\ObjectId();
$hex = (string) $id;
echo 'oid_len=', strlen($hex), "\n";
echo 'oid_hex=', ctype_xdigit($hex) ? 'Y' : 'N', "\n";
echo 'oid_ts=', $id->getTimestamp() > 0 ? 'Y' : 'N', "\n";

$id2 = new MongoDB\BSON\ObjectId($hex);
echo 'oid_round=', ((string) $id2) === $hex ? 'Y' : 'N', "\n";

$cmd = new MongoDB\Driver\Command(['ping' => 1]);
echo 'cmd=', get_class($cmd), "\n";
$rp = new MongoDB\Driver\ReadPreference(MongoDB\Driver\ReadPreference::RP_PRIMARY);
echo 'rp=', get_class($rp), "\n";
$wc = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY);
echo 'wc=', get_class($wc), "\n";

$dt = new MongoDB\BSON\UTCDateTime(0);
echo 'utc=', (string) $dt, "\n";

echo 'fromJSON=', function_exists('MongoDB\\BSON\\fromJSON') ? 'Y' : 'N', "\n";
echo 'toJSON=', function_exists('MongoDB\\BSON\\toJSON') ? 'Y' : 'N', "\n";
if (function_exists('MongoDB\\BSON\\fromJSON') && function_exists('MongoDB\\BSON\\toJSON')) {
    $doc = MongoDB\BSON\fromJSON('{"a":1}');
    echo 'json_round=', MongoDB\BSON\toJSON($doc), "\n";
}
