<?php
$fns = [
  "chunk_split" => fn() => chunk_split(null),
  "explode" => fn() => explode(",", null),
  "addslashes" => fn() => addslashes(null),
  "str_rot13" => fn() => str_rot13(null),
  "count_chars" => fn() => count_chars(null, 3),
  "str_word_count" => fn() => str_word_count(null),
  "crc32" => fn() => crc32(null),
  "base_convert" => fn() => base_convert(null, 10, 16),
  "convert_uuencode" => fn() => convert_uuencode(null),
  "quotemeta" => fn() => quotemeta(null),
];
foreach ($fns as $name => $fn) {
  try {
    $fn();
    echo "$name: coerce\n";
  } catch (TypeError $e) {
    echo "$name: TypeError\n";
  } catch (Throwable $e) {
    echo "$name: ", get_class($e), ":", $e->getMessage(), "\n";
  }
}
