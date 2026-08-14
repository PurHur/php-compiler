<?php
/**
 * token_get_all / xml_parser_create / xml_parse excess argc → Zend ArgumentCountError (#30890).
 * php-src: ext/tokenizer/tokenizer.stub.php, ext/xml/xml.stub.php
 */
function t(string $label, callable $fn): void
{
    try {
        $fn();
        echo $label, ": ACCEPTED\n";
    } catch (ArgumentCountError $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    } catch (Throwable $e) {
        echo $label, ':', get_class($e), ':', $e->getMessage(), "\n";
    }
}

t('token_get_all_hi', static fn () => token_get_all('<?php echo 1;', 0, 1));
t('token_get_all_lo', static fn () => token_get_all());
t('xml_parser_create_hi', static fn () => xml_parser_create('UTF-8', 1));
t('xml_parse_hi', static function (): void {
    $p = xml_parser_create();
    xml_parse($p, '<a/>', true, 1);
});
t('xml_parse_lo', static fn () => xml_parse());
echo 'ok_token:', is_array(token_get_all('<?php echo 1;', 0)) ? '1' : '0', "\n";
echo 'ok_parser:', get_class(xml_parser_create('UTF-8')), "\n";
$p = xml_parser_create();
echo 'ok_parse:', (string) xml_parse($p, '<a/>', true), "\n";
