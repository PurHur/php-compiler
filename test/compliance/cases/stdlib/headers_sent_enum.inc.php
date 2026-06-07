<?php
enum Name: string {
    case N = 'n';
}
enum Line: int {
    case L = 1;
}

$file = Name::N;
$line = Line::L;
$r = headers_sent($file, $line);
if ($r) {
    $enumSent = 'sent';
} else {
    $enumSent = 'not-sent';
}
$enumFileType = gettype($file);
$enumFileLen = strlen($file);
$enumLine = $line;

$file = '';
$line = 0;
try {
    headers_sent(Name::N, $line);
    echo "file literal uncaught\n";
} catch (Throwable $e) {
    echo 'file literal: ', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
try {
    headers_sent($file, Line::L);
    echo "line literal uncaught\n";
} catch (Throwable $e) {
    echo 'line literal: ', get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

echo $enumSent, "\n";
echo $enumFileType, "\n";
echo $enumFileLen, "\n";
echo $enumLine, "\n";
