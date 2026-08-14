--TEST--
createFromFormat() invalid clock overflows + warning at input offset (#30972, ext/date/lib/parse_date.c)
--FILE--
<?php
function dump_err($e): void
{
    if (false === $e) {
        echo "err=false\n";
        return;
    }
    $keys = array_keys($e['warnings'] ?? []);
    echo 'wc=', (string) ($e['warning_count'] ?? 0),
        ' key=', (string) ($keys[0] ?? ''),
        ' msg=', $e['warnings'][$keys[0] ?? -1] ?? '',
        "\n";
}

$h25 = DateTime::createFromFormat('H:i', '25:00');
$e25 = DateTime::getLastErrors();
echo false === $h25 ? "false\n" : $h25->format('H:i:s')."\n";
echo (false !== $h25 && $h25->format('Y-m-d') !== date('Y-m-d')) ? "next_day\n" : "not_next\n";
dump_err($e25);

$i60 = DateTime::createFromFormat('H:i', '12:60');
echo false === $i60 ? "false\n" : $i60->format('H:i:s')."\n";
dump_err(DateTime::getLastErrors());

$imm = DateTimeImmutable::createFromFormat('H:i', '25:00');
echo false === $imm ? "false\n" : $imm->format('H:i:s')."\n";
dump_err(DateTimeImmutable::getLastErrors());

$fn = date_create_from_format('H:i', '25:00');
echo false === $fn ? "false\n" : $fn->format('H:i:s')."\n";
dump_err(date_get_last_errors());

$cal = DateTime::createFromFormat('Y-m-d H:i:s', '2024-02-31 12:00:00');
echo false === $cal ? "false\n" : $cal->format('Y-m-d H:i:s')."\n";
dump_err(DateTime::getLastErrors());

$bang = DateTime::createFromFormat('!H:i', '25:00');
echo false === $bang ? "false\n" : $bang->format('Y-m-d H:i:s')."\n";
dump_err(DateTime::getLastErrors());

$p = date_parse_from_format('H:i', '25:00');
echo $p['hour'], ' ', $p['warning_count'], ' ', ($p['warnings'][5] ?? ''), "\n";
?>
--EXPECT--
01:00:00
next_day
wc=1 key=5 msg=The parsed time was invalid
13:00:00
wc=1 key=5 msg=The parsed time was invalid
01:00:00
wc=1 key=5 msg=The parsed time was invalid
01:00:00
wc=1 key=5 msg=The parsed time was invalid
2024-03-02 12:00:00
wc=1 key=19 msg=The parsed date was invalid
1970-01-02 01:00:00
wc=1 key=5 msg=The parsed time was invalid
25 1 The parsed time was invalid
