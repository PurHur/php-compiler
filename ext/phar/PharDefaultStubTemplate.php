<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

/**
 * php-src default Phar stub template (ext/phar/stub.h from shortarc.php / makestub.php).
 *
 * Assembles the same stub text as zim_Phar_createDefaultStub / phar_get_stub (#22292).
 * Parts are base64 of the fixed fragments around $web / START / LEN substitutions.
 */
final class PharDefaultStubTemplate
{
    /** php-src stub.h newstub_len — fixed fragment size excluding index/web + LEN digits. */
    private const NEWSTUB_LEN = 6623;

    /** @var ?array{prefix: string, mid1: string, mid2: string, suffix: string} */
    private static ?array $parts = null;

    /**
     * @return array{prefix: string, mid1: string, mid2: string, suffix: string}
     */
    private static function parts(): array
    {
        if (null === self::$parts) {
            self::$parts = [
                'prefix' => \base64_decode(self::PREFIX_B64, true),
                'mid1' => \base64_decode(self::MID1_B64, true),
                'mid2' => \base64_decode(self::MID2_B64, true),
                'suffix' => \base64_decode(self::SUFFIX_B64, true),
            ];
        }

        return self::$parts;
    }

    /**
     * Build default stub — php-src phar_create_default_stub + phar_get_stub.
     *
     * null defaults to "index.php"; empty string is preserved (Zend |P!P! behavior).
     *
     * @throws \PharException when index or web filename exceeds 400 characters
     */
    public static function build(?string $indexPhp, ?string $webIndex): string
    {
        if (null === $indexPhp) {
            $indexPhp = 'index.php';
        }
        if (null === $webIndex) {
            $webIndex = 'index.php';
        }

        $indexLen = \strlen($indexPhp);
        $webLen = \strlen($webIndex);

        if ($indexLen > 400) {
            throw new \PharException(
                'Illegal filename passed in for stub creation, was '.$indexLen
                .' characters long, and only 400 or less is allowed'
            );
        }
        if ($webLen > 400) {
            throw new \PharException(
                'Illegal web filename passed in for stub creation, was '.$webLen
                .' characters long, and only 400 or less is allowed'
            );
        }

        // php-src: name_len/web_len include trailing NUL in LEN arithmetic
        $len = self::NEWSTUB_LEN + ($indexLen + 1) + ($webLen + 1);
        $p = self::parts();

        return $p['prefix']
            ."\$web = '".$webIndex."';"
            .$p['mid1']
            ."const START = '".$indexPhp."';"
            .$p['mid2']
            .'const LEN = '.$len.';'
            .$p['suffix'];
    }

    private const PREFIX_B64 = 'PD9waHAKCg==';

    private const MID1_B64 = 'CgppZiAoaW5fYXJyYXkoJ3BoYXInLCBzdHJlYW1fZ2V0X3dyYXBwZXJzKCkpICYmIGNsYXNzX2V4aXN0cygnUGhhcicsIDApKSB7ClBoYXI6OmludGVyY2VwdEZpbGVGdW5jcygpOwpzZXRfaW5jbHVkZV9wYXRoKCdwaGFyOi8vJyAuIF9fRklMRV9fIC4gUEFUSF9TRVBBUkFUT1IgLiBnZXRfaW5jbHVkZV9wYXRoKCkpOwpQaGFyOjp3ZWJQaGFyKG51bGwsICR3ZWIpOwppbmNsdWRlICdwaGFyOi8vJyAuIF9fRklMRV9fIC4gJy8nIC4gRXh0cmFjdF9QaGFyOjpTVEFSVDsKcmV0dXJuOwp9CgppZiAoQChpc3NldCgkX1NFUlZFUlsnUkVRVUVTVF9VUkknXSkgJiYgaXNzZXQoJF9TRVJWRVJbJ1JFUVVFU1RfTUVUSE9EJ10pICYmICgkX1NFUlZFUlsnUkVRVUVTVF9NRVRIT0QnXSA9PSAnR0VUJyB8fCAkX1NFUlZFUlsnUkVRVUVTVF9NRVRIT0QnXSA9PSAnUE9TVCcpKSkgewpFeHRyYWN0X1BoYXI6OmdvKHRydWUpOwokbWltZXMgPSBhcnJheSgKJ3BocHMnID0+IDIsCidjJyA9PiAndGV4dC9wbGFpbicsCidjYycgPT4gJ3RleHQvcGxhaW4nLAonY3BwJyA9PiAndGV4dC9wbGFpbicsCidjKysnID0+ICd0ZXh0L3BsYWluJywKJ2R0ZCcgPT4gJ3RleHQvcGxhaW4nLAonaCcgPT4gJ3RleHQvcGxhaW4nLAonbG9nJyA9PiAndGV4dC9wbGFpbicsCidybmcnID0+ICd0ZXh0L3BsYWluJywKJ3R4dCcgPT4gJ3RleHQvcGxhaW4nLAoneHNkJyA9PiAndGV4dC9wbGFpbicsCidwaHAnID0+IDEsCidpbmMnID0+IDEsCidhdmknID0+ICd2aWRlby9hdmknLAonYm1wJyA9PiAnaW1hZ2UvYm1wJywKJ2NzcycgPT4gJ3RleHQvY3NzJywKJ2dpZicgPT4gJ2ltYWdlL2dpZicsCidodG0nID0+ICd0ZXh0L2h0bWwnLAonaHRtbCcgPT4gJ3RleHQvaHRtbCcsCidodG1scycgPT4gJ3RleHQvaHRtbCcsCidpY28nID0+ICdpbWFnZS94LWljbycsCidqcGUnID0+ICdpbWFnZS9qcGVnJywKJ2pwZycgPT4gJ2ltYWdlL2pwZWcnLAonanBlZycgPT4gJ2ltYWdlL2pwZWcnLAonanMnID0+ICdhcHBsaWNhdGlvbi94LWphdmFzY3JpcHQnLAonbWlkaScgPT4gJ2F1ZGlvL21pZGknLAonbWlkJyA9PiAnYXVkaW8vbWlkaScsCidtb2QnID0+ICdhdWRpby9tb2QnLAonbW92JyA9PiAnbW92aWUvcXVpY2t0aW1lJywKJ21wMycgPT4gJ2F1ZGlvL21wMycsCidtcGcnID0+ICd2aWRlby9tcGVnJywKJ21wZWcnID0+ICd2aWRlby9tcGVnJywKJ3BkZicgPT4gJ2FwcGxpY2F0aW9uL3BkZicsCidwbmcnID0+ICdpbWFnZS9wbmcnLAonc3dmJyA9PiAnYXBwbGljYXRpb24vc2hvY2t3YXZlLWZsYXNoJywKJ3RpZicgPT4gJ2ltYWdlL3RpZmYnLAondGlmZicgPT4gJ2ltYWdlL3RpZmYnLAond2F2JyA9PiAnYXVkaW8vd2F2JywKJ3hibScgPT4gJ2ltYWdlL3hibScsCid4bWwnID0+ICd0ZXh0L3htbCcsCik7CgpoZWFkZXIoIkNhY2hlLUNvbnRyb2w6IG5vLWNhY2hlLCBtdXN0LXJldmFsaWRhdGUiKTsKaGVhZGVyKCJQcmFnbWE6IG5vLWNhY2hlIik7CgokYmFzZW5hbWUgPSBiYXNlbmFtZShfX0ZJTEVfXyk7CmlmICghc3RycG9zKCRfU0VSVkVSWydSRVFVRVNUX1VSSSddLCAkYmFzZW5hbWUpKSB7CmNoZGlyKEV4dHJhY3RfUGhhcjo6JHRlbXApOwppbmNsdWRlICR3ZWI7CnJldHVybjsKfQokcHQgPSBzdWJzdHIoJF9TRVJWRVJbJ1JFUVVFU1RfVVJJJ10sIHN0cnBvcygkX1NFUlZFUlsnUkVRVUVTVF9VUkknXSwgJGJhc2VuYW1lKSArIHN0cmxlbigkYmFzZW5hbWUpKTsKaWYgKCEkcHQgfHwgJHB0ID09ICcvJykgewokcHQgPSAkd2ViOwpoZWFkZXIoJ0hUVFAvMS4xIDMwMSBNb3ZlZCBQZXJtYW5lbnRseScpOwpoZWFkZXIoJ0xvY2F0aW9uOiAnIC4gJF9TRVJWRVJbJ1JFUVVFU1RfVVJJJ10gLiAnLycgLiAkcHQpOwpleGl0Owp9CiRhID0gcmVhbHBhdGgoRXh0cmFjdF9QaGFyOjokdGVtcCAuIERJUkVDVE9SWV9TRVBBUkFUT1IgLiAkcHQpOwppZiAoISRhIHx8IHN0cmxlbihkaXJuYW1lKCRhKSkgPCBzdHJsZW4oRXh0cmFjdF9QaGFyOjokdGVtcCkpIHsKaGVhZGVyKCdIVFRQLzEuMCA0MDQgTm90IEZvdW5kJyk7CmVjaG8gIjxodG1sPlxuIDxoZWFkPlxuICA8dGl0bGU+RmlsZSBOb3QgRm91bmQ8dGl0bGU+XG4gPC9oZWFkPlxuIDxib2R5PlxuICA8aDE+NDA0IC0gRmlsZSBOb3QgRm91bmQ8L2gxPlxuIDwvYm9keT5cbjwvaHRtbD4iOwpleGl0Owp9CiRiID0gcGF0aGluZm8oJGEpOwppZiAoIWlzc2V0KCRiWydleHRlbnNpb24nXSkpIHsKaGVhZGVyKCdDb250ZW50LVR5cGU6IHRleHQvcGxhaW4nKTsKaGVhZGVyKCdDb250ZW50LUxlbmd0aDogJyAuIGZpbGVzaXplKCRhKSk7CnJlYWRmaWxlKCRhKTsKZXhpdDsKfQppZiAoaXNzZXQoJG1pbWVzWyRiWydleHRlbnNpb24nXV0pKSB7CmlmICgkbWltZXNbJGJbJ2V4dGVuc2lvbiddXSA9PT0gMSkgewppbmNsdWRlICRhOwpleGl0Owp9CmlmICgkbWltZXNbJGJbJ2V4dGVuc2lvbiddXSA9PT0gMikgewpoaWdobGlnaHRfZmlsZSgkYSk7CmV4aXQ7Cn0KaGVhZGVyKCdDb250ZW50LVR5cGU6ICcgLiRtaW1lc1skYlsnZXh0ZW5zaW9uJ11dKTsKaGVhZGVyKCdDb250ZW50LUxlbmd0aDogJyAuIGZpbGVzaXplKCRhKSk7CnJlYWRmaWxlKCRhKTsKZXhpdDsKfQp9CgpjbGFzcyBFeHRyYWN0X1BoYXIKewpzdGF0aWMgJHRlbXA7CnN0YXRpYyAkb3JpZ2RpcjsKY29uc3QgR1ogPSAweDEwMDA7CmNvbnN0IEJaMiA9IDB4MjAwMDsKY29uc3QgTUFTSyA9IDB4MzAwMDsK';

    private const MID2_B64 = 'Cg==';

    private const SUFFIX_B64 = 'CgpzdGF0aWMgZnVuY3Rpb24gZ28oJHJldHVybiA9IGZhbHNlKQp7CiRmcCA9IGZvcGVuKF9fRklMRV9fLCAncmInKTsKZnNlZWsoJGZwLCBzZWxmOjpMRU4pOwokTCA9IHVucGFjaygnVicsICRhID0gZnJlYWQoJGZwLCA0KSk7CiRtID0gJyc7CgpkbyB7CiRyZWFkID0gODE5MjsKaWYgKCRMWzFdIC0gc3RybGVuKCRtKSA8IDgxOTIpIHsKJHJlYWQgPSAkTFsxXSAtIHN0cmxlbigkbSk7Cn0KJGxhc3QgPSBmcmVhZCgkZnAsICRyZWFkKTsKJG0gLj0gJGxhc3Q7Cn0gd2hpbGUgKHN0cmxlbigkbGFzdCkgJiYgc3RybGVuKCRtKSA8ICRMWzFdKTsKCmlmIChzdHJsZW4oJG0pIDwgJExbMV0pIHsKZGllKCdFUlJPUjogbWFuaWZlc3QgbGVuZ3RoIHJlYWQgd2FzICInIC4Kc3RybGVuKCRtKSAuJyIgc2hvdWxkIGJlICInIC4KJExbMV0gLiAnIicpOwp9CgokaW5mbyA9IHNlbGY6Ol91bnBhY2soJG0pOwokZiA9ICRpbmZvWydjJ107CgppZiAoJGYgJiBzZWxmOjpHWikgewppZiAoIWZ1bmN0aW9uX2V4aXN0cygnZ3ppbmZsYXRlJykpIHsKZGllKCdFcnJvcjogemxpYiBleHRlbnNpb24gaXMgbm90IGVuYWJsZWQgLScgLgonIGd6aW5mbGF0ZSgpIGZ1bmN0aW9uIG5lZWRlZCBmb3IgemxpYi1jb21wcmVzc2VkIC5waGFycycpOwp9Cn0KCmlmICgkZiAmIHNlbGY6OkJaMikgewppZiAoIWZ1bmN0aW9uX2V4aXN0cygnYnpkZWNvbXByZXNzJykpIHsKZGllKCdFcnJvcjogYnppcDIgZXh0ZW5zaW9uIGlzIG5vdCBlbmFibGVkIC0nIC4KJyBiemRlY29tcHJlc3MoKSBmdW5jdGlvbiBuZWVkZWQgZm9yIGJ6Mi1jb21wcmVzc2VkIC5waGFycycpOwp9Cn0KCiR0ZW1wID0gc2VsZjo6dG1wZGlyKCk7CgppZiAoISR0ZW1wIHx8ICFpc193cml0YWJsZSgkdGVtcCkpIHsKJHNlc3Npb25wYXRoID0gc2Vzc2lvbl9zYXZlX3BhdGgoKTsKaWYgKHN0cnBvcyAoJHNlc3Npb25wYXRoLCAiOyIpICE9PSBmYWxzZSkKJHNlc3Npb25wYXRoID0gc3Vic3RyICgkc2Vzc2lvbnBhdGgsIHN0cnBvcyAoJHNlc3Npb25wYXRoLCAiOyIpKzEpOwppZiAoIWZpbGVfZXhpc3RzKCRzZXNzaW9ucGF0aCkgfHwgIWlzX2Rpcigkc2Vzc2lvbnBhdGgpKSB7CmRpZSgnQ291bGQgbm90IGxvY2F0ZSB0ZW1wb3JhcnkgZGlyZWN0b3J5IHRvIGV4dHJhY3QgcGhhcicpOwp9CiR0ZW1wID0gJHNlc3Npb25wYXRoOwp9CgokdGVtcCAuPSAnL3BoYXJleHRyYWN0LycuYmFzZW5hbWUoX19GSUxFX18sICcucGhhcicpOwpzZWxmOjokdGVtcCA9ICR0ZW1wOwpzZWxmOjokb3JpZ2RpciA9IGdldGN3ZCgpOwpAbWtkaXIoJHRlbXAsIDA3NzcsIHRydWUpOwokdGVtcCA9IHJlYWxwYXRoKCR0ZW1wKTsKCmlmICghZmlsZV9leGlzdHMoJHRlbXAgLiBESVJFQ1RPUllfU0VQQVJBVE9SIC4gbWQ1X2ZpbGUoX19GSUxFX18pKSkgewpzZWxmOjpfcmVtb3ZlVG1wRmlsZXMoJHRlbXAsIGdldGN3ZCgpKTsKQG1rZGlyKCR0ZW1wLCAwNzc3LCB0cnVlKTsKQGZpbGVfcHV0X2NvbnRlbnRzKCR0ZW1wIC4gJy8nIC4gbWQ1X2ZpbGUoX19GSUxFX18pLCAnJyk7Cgpmb3JlYWNoICgkaW5mb1snbSddIGFzICRwYXRoID0+ICRmaWxlKSB7CiRhID0gIWZpbGVfZXhpc3RzKGRpcm5hbWUoJHRlbXAgLiAnLycgLiAkcGF0aCkpOwpAbWtkaXIoZGlybmFtZSgkdGVtcCAuICcvJyAuICRwYXRoKSwgMDc3NywgdHJ1ZSk7CmNsZWFyc3RhdGNhY2hlKCk7CgppZiAoJHBhdGhbc3RybGVuKCRwYXRoKSAtIDFdID09ICcvJykgewpAbWtkaXIoJHRlbXAgLiAnLycgLiAkcGF0aCwgMDc3Nyk7Cn0gZWxzZSB7CmZpbGVfcHV0X2NvbnRlbnRzKCR0ZW1wIC4gJy8nIC4gJHBhdGgsIHNlbGY6OmV4dHJhY3RGaWxlKCRwYXRoLCAkZmlsZSwgJGZwKSk7CkBjaG1vZCgkdGVtcCAuICcvJyAuICRwYXRoLCAwNjY2KTsKfQp9Cn0KCmNoZGlyKCR0ZW1wKTsKCmlmICghJHJldHVybikgewppbmNsdWRlIHNlbGY6OlNUQVJUOwp9Cn0KCnN0YXRpYyBmdW5jdGlvbiB0bXBkaXIoKQp7CmlmIChzdHJwb3MoUEhQX09TLCAnV0lOJykgIT09IGZhbHNlKSB7CmlmICgkdmFyID0gZ2V0ZW52KCdUTVAnKSA/IGdldGVudignVE1QJykgOiBnZXRlbnYoJ1RFTVAnKSkgewpyZXR1cm4gJHZhcjsKfQppZiAoaXNfZGlyKCcvdGVtcCcpIHx8IG1rZGlyKCcvdGVtcCcpKSB7CnJldHVybiByZWFscGF0aCgnL3RlbXAnKTsKfQpyZXR1cm4gZmFsc2U7Cn0KaWYgKCR2YXIgPSBnZXRlbnYoJ1RNUERJUicpKSB7CnJldHVybiAkdmFyOwp9CnJldHVybiByZWFscGF0aCgnL3RtcCcpOwp9CgpzdGF0aWMgZnVuY3Rpb24gX3VucGFjaygkbSkKewokaW5mbyA9IHVucGFjaygnVicsIHN1YnN0cigkbSwgMCwgNCkpOwogJGwgPSB1bnBhY2soJ1YnLCBzdWJzdHIoJG0sIDEwLCA0KSk7CiRtID0gc3Vic3RyKCRtLCAxNCArICRsWzFdKTsKJHMgPSB1bnBhY2soJ1YnLCBzdWJzdHIoJG0sIDAsIDQpKTsKJG8gPSAwOwokc3RhcnQgPSA0ICsgJHNbMV07CiRyZXRbJ2MnXSA9IDA7Cgpmb3IgKCRpID0gMDsgJGkgPCAkaW5mb1sxXTsgJGkrKykgewogJGxlbiA9IHVucGFjaygnVicsIHN1YnN0cigkbSwgJHN0YXJ0LCA0KSk7CiRzdGFydCArPSA0OwogJHNhdmVwYXRoID0gc3Vic3RyKCRtLCAkc3RhcnQsICRsZW5bMV0pOwokc3RhcnQgKz0gJGxlblsxXTsKICAgJHJldFsnbSddWyRzYXZlcGF0aF0gPSBhcnJheV92YWx1ZXModW5wYWNrKCdWYS9WYi9WYy9WZC9WZS9WZicsIHN1YnN0cigkbSwgJHN0YXJ0LCAyNCkpKTsKJHJldFsnbSddWyRzYXZlcGF0aF1bM10gPSBzcHJpbnRmKCcldScsICRyZXRbJ20nXVskc2F2ZXBhdGhdWzNdCiYgMHhmZmZmZmZmZik7CiRyZXRbJ20nXVskc2F2ZXBhdGhdWzddID0gJG87CiRvICs9ICRyZXRbJ20nXVskc2F2ZXBhdGhdWzJdOwokc3RhcnQgKz0gMjQgKyAkcmV0WydtJ11bJHNhdmVwYXRoXVs1XTsKJHJldFsnYyddIHw9ICRyZXRbJ20nXVskc2F2ZXBhdGhdWzRdICYgc2VsZjo6TUFTSzsKfQpyZXR1cm4gJHJldDsKfQoKc3RhdGljIGZ1bmN0aW9uIGV4dHJhY3RGaWxlKCRwYXRoLCAkZW50cnksICRmcCkKewokZGF0YSA9ICcnOwokYyA9ICRlbnRyeVsyXTsKCndoaWxlICgkYykgewppZiAoJGMgPCA4MTkyKSB7CiRkYXRhIC49IEBmcmVhZCgkZnAsICRjKTsKJGMgPSAwOwp9IGVsc2UgewokYyAtPSA4MTkyOwokZGF0YSAuPSBAZnJlYWQoJGZwLCA4MTkyKTsKfQp9CgppZiAoJGVudHJ5WzRdICYgc2VsZjo6R1opIHsKJGRhdGEgPSBnemluZmxhdGUoJGRhdGEpOwp9IGVsc2VpZiAoJGVudHJ5WzRdICYgc2VsZjo6QloyKSB7CiRkYXRhID0gYnpkZWNvbXByZXNzKCRkYXRhKTsKfQoKaWYgKHN0cmxlbigkZGF0YSkgIT0gJGVudHJ5WzBdKSB7CmRpZSgiSW52YWxpZCBpbnRlcm5hbCAucGhhciBmaWxlIChzaXplIGVycm9yICIgLiBzdHJsZW4oJGRhdGEpIC4gIiAhPSAiIC4KJHN0YXRbN10gLiAiKSIpOwp9CgppZiAoJGVudHJ5WzNdICE9IHNwcmludGYoIiV1IiwgY3JjMzIoJGRhdGEpICYgMHhmZmZmZmZmZikpIHsKZGllKCJJbnZhbGlkIGludGVybmFsIC5waGFyIGZpbGUgKGNoZWNrc3VtIGVycm9yKSIpOwp9CgpyZXR1cm4gJGRhdGE7Cn0KCnN0YXRpYyBmdW5jdGlvbiBfcmVtb3ZlVG1wRmlsZXMoJHRlbXAsICRvcmlnZGlyKQp7CmNoZGlyKCR0ZW1wKTsKCmZvcmVhY2ggKGdsb2IoJyonKSBhcyAkZikgewppZiAoZmlsZV9leGlzdHMoJGYpKSB7CmlzX2RpcigkZikgPyBAcm1kaXIoJGYpIDogQHVubGluaygkZik7CmlmIChmaWxlX2V4aXN0cygkZikgJiYgaXNfZGlyKCRmKSkgewpzZWxmOjpfcmVtb3ZlVG1wRmlsZXMoJGYsIGdldGN3ZCgpKTsKfQp9Cn0KCkBybWRpcigkdGVtcCk7CmNsZWFyc3RhdGNhY2hlKCk7CmNoZGlyKCRvcmlnZGlyKTsKfQp9CgpFeHRyYWN0X1BoYXI6OmdvKCk7Cl9fSEFMVF9DT01QSUxFUigpOyA/Pg==';
}
