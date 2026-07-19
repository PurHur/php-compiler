--TEST--
stdlib DateInterval malformed spec throws Exception on 8.2 reference profile (#16490, #20779)
--FILE--
<?php
try {
    new DateInterval('P');
    echo "no throw\n";
} catch (DateMalformedIntervalStringException $e) {
    echo "wrong: DateMalformedIntervalStringException\n";
} catch (DateMalformedIntervalException $e) {
    echo "wrong: DateMalformedIntervalException\n";
} catch (Exception $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
Unknown or bad format (P)
