<?php
foreach ([DateTime::class, DateTimeImmutable::class] as $cls) {
    try {
        $o = new $cls('now', null, 1);
        echo $cls, " NO_THROW ", $o->format('Y'), "\n";
    } catch (ArgumentCountError $e) {
        echo $cls, ' ', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $cls, ' ', get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    $o = new DateTime('now');
    echo 'DT_OK ', $o->format('Y') !== '' ? 'yes' : 'no', "\n";
} catch (Throwable $e) {
    echo 'DT_OK ', get_class($e), ':', $e->getMessage(), "\n";
}
try {
    $tz = new DateTimeZone('UTC');
    $o = new DateTimeImmutable('2020-01-01', $tz);
    echo 'DTI_OK ', $o->format('Y-m-d'), "\n";
} catch (Throwable $e) {
    echo 'DTI_OK ', get_class($e), ':', $e->getMessage(), "\n";
}
