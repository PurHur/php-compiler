--TEST--
curl_strerror() uses live libcurl curl_easy_strerror (#25813)
--FILE--
<?php
declare(strict_types=1);

foreach ([0, 3, 6, 7, 45, 52, 55, 56, 9999] as $c) {
    echo $c, '=', curl_strerror($c), "\n";
}
?>
--EXPECT--
0=No error
3=URL using bad/illegal format or missing URL
6=Couldn't resolve host name
7=Couldn't connect to server
45=Failed binding local connection end
52=Server returned nothing (no headers, no data)
55=Failed sending data to the peer
56=Failure when receiving data from the peer
9999=Unknown error
