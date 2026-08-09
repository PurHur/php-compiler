--TEST--
stdlib mongodb BSON + Driver surface when advertised (#27875)
--SKIPIF--
<?php
if (!\PHPCompiler\CompilerVersion::supportsMongodb()) {
    die('skip mongodb withheld on reference profile (#6575/#27875)');
}
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('mongodb') ? '1' : '0';
foreach ([
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
    echo class_exists($c, false) ? '1' : '0';
}
echo function_exists('MongoDB\\BSON\\fromJSON') ? '1' : '0';
echo function_exists('MongoDB\\BSON\\toJSON') ? '1' : '0';
echo "\n";

$id = new MongoDB\BSON\ObjectId();
$hex = (string) $id;
echo strlen($hex) === 24 && ctype_xdigit($hex) ? 'oid_ok' : 'oid_bad';
echo "\n";
echo ((string) new MongoDB\BSON\ObjectId($hex)) === $hex ? 'oid_rt' : 'oid_rt_bad';
echo "\n";
echo $id->getTimestamp() > 0 ? 'oid_ts' : 'oid_ts_bad';
echo "\n";

$cmd = new MongoDB\Driver\Command(['ping' => 1]);
echo get_class($cmd), "\n";
$rp = new MongoDB\Driver\ReadPreference(MongoDB\Driver\ReadPreference::RP_PRIMARY);
echo $rp->getModeString(), "\n";
$wc = new MongoDB\Driver\WriteConcern(MongoDB\Driver\WriteConcern::MAJORITY);
echo $wc->getW(), "\n";

$bin = new MongoDB\BSON\Binary('abc', MongoDB\BSON\Binary::TYPE_GENERIC);
echo $bin->getData(), ':', $bin->getType(), "\n";
$re = new MongoDB\BSON\Regex('foo', 'i');
echo (string) $re, "\n";
$dec = new MongoDB\BSON\Decimal128('1.5');
echo (string) $dec, "\n";
$ts = new MongoDB\BSON\Timestamp(1, 2);
echo (string) $ts, "\n";
$utc = new MongoDB\BSON\UTCDateTime(0);
echo (string) $utc, "\n";

$doc = MongoDB\BSON\fromJSON('{"a":1}');
echo MongoDB\BSON\toJSON($doc), "\n";
?>
--EXPECT--
11111111111111
oid_ok
oid_rt
oid_ts
MongoDB\Driver\Command
primary
majority
abc:0
/foo/i
1.5
[1:2]
0
{"a":1}
