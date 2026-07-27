<?php
/**
 * Repro for #23605 — Reflection / named-arg param names for
 * curl_multi_setopt, xml_parse, mail, sodium_memzero, exif_read_data.
 *
 * php-src stubs: curl.stub.php, xml.stub.php, basic_functions.stub.php,
 * sodium.stub.php, exif.stub.php.
 */

$pass = true;
$expect = [
    'curl_multi_setopt' => ['multi_handle', 'option', 'value'],
    'xml_parse' => ['parser', 'data', 'is_final'],
    'mail' => ['to', 'subject', 'message', 'additional_headers', 'additional_params'],
    'sodium_memzero' => ['string'],
    'exif_read_data' => ['file', 'required_sections', 'as_arrays', 'read_thumbnail'],
];

foreach ($expect as $fn => $expectedNames) {
    $rf = new ReflectionFunction($fn);
    $actual = array_map(static fn ($p) => $p->getName(), $rf->getParameters());
    if ($actual !== $expectedNames) {
        echo "FAIL $fn Reflection: got [" . implode(', ', $actual) . '] expected [' . implode(', ', $expectedNames) . "]\n";
        $pass = false;
    }
}

$mh = curl_multi_init();
try {
    curl_multi_setopt(multi_handle: $mh, option: CURLMOPT_MAXCONNECTS, value: 2);
} catch (Throwable $t) {
    echo 'FAIL curl_multi_setopt named: ' . get_class($t) . ': ' . $t->getMessage() . "\n";
    $pass = false;
}
curl_multi_close($mh);

$parser = xml_parser_create();
try {
    $ok = xml_parse(parser: $parser, data: '<a/>', is_final: true);
    if (1 !== $ok) {
        echo "FAIL xml_parse named: unexpected status $ok\n";
        $pass = false;
    }
} catch (Throwable $t) {
    echo 'FAIL xml_parse named: ' . get_class($t) . ': ' . $t->getMessage() . "\n";
    $pass = false;
}

try {
    // Transport may fail; named-arg acceptance is the assertion.
    @mail(to: 'a@b.c', subject: 's', message: 'm', additional_headers: '', additional_params: '');
} catch (Throwable $t) {
    echo 'FAIL mail named: ' . get_class($t) . ': ' . $t->getMessage() . "\n";
    $pass = false;
}

$s = 'secret';
try {
    sodium_memzero(string: $s);
    if (null !== $s) {
        echo "FAIL sodium_memzero named: string not nulled\n";
        $pass = false;
    }
} catch (Throwable $t) {
    echo 'FAIL sodium_memzero named: ' . get_class($t) . ': ' . $t->getMessage() . "\n";
    $pass = false;
}

try {
    @exif_read_data(
        file: '/nonexistent-exif-23605.jpg',
        required_sections: '',
        as_arrays: false,
        read_thumbnail: false
    );
} catch (Error $e) {
    // Unknown named parameter = fail; missing-file warnings are OK.
    if (str_contains($e->getMessage(), 'Unknown named parameter')) {
        echo 'FAIL exif_read_data named: ' . $e->getMessage() . "\n";
        $pass = false;
    }
} catch (Throwable $t) {
    if (str_contains($t->getMessage(), 'Unknown named parameter')) {
        echo 'FAIL exif_read_data named: ' . $t->getMessage() . "\n";
        $pass = false;
    }
}

echo $pass ? "PASS\n" : "FAIL\n";
