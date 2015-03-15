<?php
namespace TechData\AS2SecureBundle\Models\Horde;
/**
 * The Horde_String:: class provides static methods for charset and locale safe
 * string manipulation.
 *
 * $Horde: framework/Util/Horde_String.php,v 1.43.6.37 2009/03/30 15:31:38 jan Exp $
 *
 * Copyright 2003-2009 The Horde Project (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.fsf.org/copyleft/lgpl.html.
 *
 * @author  Jan Schneider <jan@horde.org>
 * @since   Horde 3.0
 * @package Horde_Util
 */
class Horde_String
{

    public static $charset = 'UTF-8';

    /**
     * Caches the result of extension_loaded() calls.
     *
     * @param string $ext The extension name.
     *
     * @return boolean  Is the extension loaded?
     *
     * @see Util::extensionExists()
     */
    public function extensionExists($ext)
    {
        static $cache = array();

        if (!isset($cache[$ext])) {
            $cache[$ext] = extension_loaded($ext);
        }

        return $cache[$ext];
    }

    /**
     * Sets a default charset that the Horde_String:: methods will use if none is
     * explicitly specified.
     *
     * @param string $charset The charset to use as the default one.
     */
    public function setDefaultCharset($charset)
    {
        self::$charset = $charset;
        if (Horde_String::extensionExists('mbstring') &&
            function_exists('mb_regex_encoding')
        ) {
            $old_error = error_reporting(0);
            mb_regex_encoding(Horde_String::_mbstringCharset($charset));
            error_reporting($old_error);
        }
    }

    /**
     * Converts a string from one charset to another.
     *
     * Works only if either the iconv or the mbstring extension
     * are present and best if both are available.
     * The original string is returned if conversion failed or none
     * of the extensions were available.
     *
     * @param mixed $input The data to be converted. If $input is an an array,
     *                      the array's values get converted recursively.
     * @param string $from The string's current charset.
     * @param string $to The charset to convert the string to. If not
     *                      specified, the global variable
     *                      $_HORDE_STRING_CHARSET will be used.
     *
     * @return mixed  The converted input data.
     */
    public function convertCharset($input, $from, $to = null)
    {
        /* Don't bother converting numbers. */
        if (is_numeric($input)) {
            return $input;
        }

        /* Get the user's default character set if none passed in. */
        if (is_null($to)) {
            $to = self::$charset;
        }

        /* If the from and to character sets are identical, return now. */
        $from = Horde_String::lower($from);
        $to = Horde_String::lower($to);
        if ($from == $to) {
            return $input;
        }

        if (is_array($input)) {
            $tmp = array();
            reset($input);
            while (list($key, $val) = each($input)) {
                $tmp[Horde_String::_convertCharset($key, $from, $to)] = Horde_String::convertCharset($val, $from, $to);
            }
            return $tmp;
        }
        if (is_object($input)) {
            $vars = get_object_vars($input);
            while (list($key, $val) = each($vars)) {
                $input->$key = Horde_String::convertCharset($val, $from, $to);
            }
            return $input;
        }

        if (!is_string($input)) {
            return $input;
        }

        return Horde_String::_convertCharset($input, $from, $to);
    }

    /**
     * Internal public function used to do charset conversion.
     *
     * @access private
     *
     * @param string $input See Horde_String::convertCharset().
     * @param string $from See Horde_String::convertCharset().
     * @param string $to See Horde_String::convertCharset().
     *
     * @return string  The converted string.
     */
    protected function _convertCharset($input, $from, $to)
    {
        $output = '';
        $from_check = (($from == 'iso-8859-1') || ($from == 'us-ascii'));
        $to_check = (($to == 'iso-8859-1') || ($to == 'us-ascii'));

        /* Use utf8_[en|de]code() if possible and if the string isn't too
         * large (less than 16 MB = 16 * 1024 * 1024 = 16777216 bytes) - these
         * public functions use more memory. */
        if (strlen($input) < 16777216 || !(Horde_String::extensionExists('iconv') || Horde_String::extensionExists('mbstring'))) {
            if ($from_check && ($to == 'utf-8')) {
                return utf8_encode($input);
            }

            if (($from == 'utf-8') && $to_check) {
                return utf8_decode($input);
            }
        }

        /* First try iconv with transliteration. */
        if (($from != 'utf7-imap') &&
            ($to != 'utf7-imap') &&
            Horde_String::extensionExists('iconv')
        ) {
            /* We need to tack an extra character temporarily because of a bug
             * in iconv() if the last character is not a 7 bit ASCII
             * character. */
            $oldTrackErrors = ini_set('track_errors', 1);
            unset($php_errormsg);
            $output = @iconv($from, $to . '//TRANSLIT', $input . 'x');
            $output = (isset($php_errormsg)) ? false : Horde_String::substr($output, 0, -1, $to);
            ini_set('track_errors', $oldTrackErrors);
        }

        /* Next try mbstring. */
        if (!$output && Horde_String::extensionExists('mbstring')) {
            $old_error = error_reporting(0);
            $output = mb_convert_encoding($input, $to, Horde_String::_mbstringCharset($from));
            error_reporting($old_error);
        }

        /* At last try imap_utf7_[en|de]code if appropriate. */
        if (!$output && Horde_String::extensionExists('imap')) {
            if ($from_check && ($to == 'utf7-imap')) {
                return @imap_utf7_encode($input);
            }
            if (($from == 'utf7-imap') && $to_check) {
                return @imap_utf7_decode($input);
            }
        }

        return (!$output) ? $input : $output;
    }

    /**
     * Makes a string lowercase.
     *
     * @param string $string The string to be converted.
     * @param boolean $locale If true the string will be converted based on a
     *                          given charset, locale independent else.
     * @param string $charset If $locale is true, the charset to use when
     *                          converting. If not provided the current charset.
     *
     * @return string  The string with lowercase characters
     */
    public function lower($string, $locale = false, $charset = null)
    {
        static $lowers;

        if ($locale) {
            /* The existence of mb_strtolower() depends on the platform. */
            if (Horde_String::extensionExists('mbstring') &&
                function_exists('mb_strtolower')
            ) {
                if (is_null($charset)) {
                    $charset = self::$charset;
                }
                $old_error = error_reporting(0);
                $ret = mb_strtolower($string, Horde_String::_mbstringCharset($charset));
                error_reporting($old_error);
                if (!empty($ret)) {
                    return $ret;
                }
            }
            return strtolower($string);
        }

        if (!isset($lowers)) {
            $lowers = array();
        }
        if (!isset($lowers[$string])) {
            $language = setlocale(LC_CTYPE, 0);
            setlocale(LC_CTYPE, 'C');
            $lowers[$string] = strtolower($string);
            setlocale(LC_CTYPE, $language);
        }

        return $lowers[$string];
    }

    /**
     * Makes a string uppercase.
     *
     * @param string $string The string to be converted.
     * @param boolean $locale If true the string will be converted based on a
     *                          given charset, locale independent else.
     * @param string $charset If $locale is true, the charset to use when
     *                          converting. If not provided the current charset.
     *
     * @return string  The string with uppercase characters
     */
    public function upper($string, $locale = false, $charset = null)
    {
        static $uppers;

        if ($locale) {
            /* The existence of mb_strtoupper() depends on the
             * platform. */
            if (function_exists('mb_strtoupper')) {
                if (is_null($charset)) {
                    $charset = self::$charset;
                }
                $old_error = error_reporting(0);
                $ret = mb_strtoupper($string, Horde_String::_mbstringCharset($charset));
                error_reporting($old_error);
                if (!empty($ret)) {
                    return $ret;
                }
            }
            return strtoupper($string);
        }

        if (!isset($uppers)) {
            $uppers = array();
        }
        if (!isset($uppers[$string])) {
            $language = setlocale(LC_CTYPE, 0);
            setlocale(LC_CTYPE, 'C');
            $uppers[$string] = strtoupper($string);
            setlocale(LC_CTYPE, $language);
        }

        return $uppers[$string];
    }

    /**
     * Returns a string with the first letter capitalized if it is
     * alphabetic.
     *
     * @param string $string The string to be capitalized.
     * @param boolean $locale If true the string will be converted based on a
     *                          given charset, locale independent else.
     * @param string $charset The charset to use, defaults to current charset.
     *
     * @return string  The capitalized string.
     */
    public function ucfirst($string, $locale = false, $charset = null)
    {
        if ($locale) {
            $first = Horde_String::substr($string, 0, 1, $charset);
            if (Horde_String::isAlpha($first, $charset)) {
                $string = Horde_String::upper($first, true, $charset) . Horde_String::substr($string, 1, null, $charset);
            }
        } else {
            $string = Horde_String::upper(substr($string, 0, 1), false) . substr($string, 1);
        }
        return $string;
    }

    /**
     * Returns part of a string.
     *
     * @param string $string The string to be converted.
     * @param integer $start The part's start position, zero based.
     * @param integer $length The part's length.
     * @param string $charset The charset to use when calculating the part's
     *                         position and length, defaults to current
     *                         charset.
     *
     * @return string  The string's part.
     */
    public function substr($string, $start, $length = null, $charset = null)
    {
        if (is_null($length)) {
            $length = Horde_String::length($string, $charset) - $start;
        }

        if ($length == 0) {
            return '';
        }

        /* Try iconv. */
        if (function_exists('iconv_substr')) {
            if (is_null($charset)) {
                $charset = self::$charset;
            }

            $old_error = error_reporting(0);
            $ret = iconv_substr($string, $start, $length, $charset);
            error_reporting($old_error);
            /* iconv_substr() returns false on failure. */
            if ($ret !== false) {
                return $ret;
            }
        }

        /* Try mbstring. */
        if (Horde_String::extensionExists('mbstring')) {
            if (is_null($charset)) {
                $charset = self::$charset;
            }
            $old_error = error_reporting(0);
            $ret = mb_substr($string, $start, $length, Horde_String::_mbstringCharset($charset));
            error_reporting($old_error);
            /* mb_substr() returns empty string on failure. */
            if (strlen($ret)) {
                return $ret;
            }
        }

        return substr($string, $start, $length);
    }

    /**
     * Returns the character (not byte) length of a string.
     *
     * @param string $string The string to return the length of.
     * @param string $charset The charset to use when calculating the string's
     *                        length.
     *
     * @return string  The string's part.
     */
    public function length($string, $charset = null)
    {
        if (is_null($charset)) {
            $charset = self::$charset;
        }
        $charset = Horde_String::lower($charset);
        if ($charset == 'utf-8' || $charset == 'utf8') {
            return strlen(utf8_decode($string));
        }
        if (Horde_String::extensionExists('mbstring')) {
            $old_error = error_reporting(0);
            $ret = mb_strlen($string, Horde_String::_mbstringCharset($charset));
            error_reporting($old_error);
            if (!empty($ret)) {
                return $ret;
            }
        }
        return strlen($string);
    }

    /**
     * Returns the numeric position of the first occurrence of $needle
     * in the $haystack string.
     *
     * @param string $haystack The string to search through.
     * @param string $needle The string to search for.
     * @param integer $offset Allows to specify which character in haystack
     *                          to start searching.
     * @param string $charset The charset to use when searching for the
     *                          $needle string.
     *
     * @return integer  The position of first occurrence.
     */
    public function pos($haystack, $needle, $offset = 0, $charset = null)
    {
        if (Horde_String::extensionExists('mbstring')) {
            if (is_null($charset)) {
                $charset = self::$charset;
            }
            $track_errors = ini_set('track_errors', 1);
            $old_error = error_reporting(0);
            $ret = mb_strpos($haystack, $needle, $offset, Horde_String::_mbstringCharset($charset));
            error_reporting($old_error);
            ini_set('track_errors', $track_errors);
            if (!isset($php_errormsg)) {
                return $ret;
            }
        }
        return strpos($haystack, $needle, $offset);
    }

    /**
     * Returns a string padded to a certain length with another string.
     *
     * This method behaves exactly like str_pad but is multibyte safe.
     *
     * @param string $input The string to be padded.
     * @param integer $length The length of the resulting string.
     * @param string $pad The string to pad the input string with. Must
     *                         be in the same charset like the input string.
     * @param const $type The padding type. One of STR_PAD_LEFT,
     *                         STR_PAD_RIGHT, or STR_PAD_BOTH.
     * @param string $charset The charset of the input and the padding
     *                         strings.
     *
     * @return string  The padded string.
     */
    public function pad($input, $length, $pad = ' ', $type = STR_PAD_RIGHT,
                        $charset = null)
    {
        $mb_length = Horde_String::length($input, $charset);
        $sb_length = strlen($input);
        $pad_length = Horde_String::length($pad, $charset);

        /* Return if we already have the length. */
        if ($mb_length >= $length) {
            return $input;
        }

        /* Shortcut for single byte strings. */
        if ($mb_length == $sb_length && $pad_length == strlen($pad)) {
            return str_pad($input, $length, $pad, $type);
        }

        switch ($type) {
            case STR_PAD_LEFT:
                $left = $length - $mb_length;
                $output = Horde_String::substr(str_repeat($pad, ceil($left / $pad_length)), 0, $left, $charset) . $input;
                break;
            case STR_PAD_BOTH:
                $left = floor(($length - $mb_length) / 2);
                $right = ceil(($length - $mb_length) / 2);
                $output = Horde_String::substr(str_repeat($pad, ceil($left / $pad_length)), 0, $left, $charset) .
                    $input .
                    Horde_String::substr(str_repeat($pad, ceil($right / $pad_length)), 0, $right, $charset);
                break;
            case STR_PAD_RIGHT:
                $right = $length - $mb_length;
                $output = $input . Horde_String::substr(str_repeat($pad, ceil($right / $pad_length)), 0, $right, $charset);
                break;
        }

        return $output;
    }

    /**
     * Wraps the text of a message.
     *
     * @since Horde 3.2
     *
     * @param string $string Horde_String containing the text to wrap.
     * @param integer $width Wrap the string at this number of
     *                               characters.
     * @param string $break Character(s) to use when breaking lines.
     * @param boolean $cut Whether to cut inside words if a line
     *                               can't be wrapped.
     * @param string $charset Character set to use when breaking lines.
     * @param boolean $line_folding Whether to apply line folding rules per
     *                               RFC 822 or similar. The correct break
     *                               characters including leading whitespace
     *                               have to be specified too.
     *
     * @return string  Horde_String containing the wrapped text.
     */
    public function wordwrap($string, $width = 75, $break = "\n", $cut = false,
                             $charset = null, $line_folding = false)
    {
        /* Get the user's default character set if none passed in. */
        if (is_null($charset)) {
            $charset = self::$charset;
        }
        $charset = Horde_String::_mbstringCharset($charset);
        $string = Horde_String::convertCharset($string, $charset, 'utf-8');
        $wrapped = '';

        while (Horde_String::length($string, 'utf-8') > $width) {
            $line = Horde_String::substr($string, 0, $width, 'utf-8');
            $string = Horde_String::substr($string, Horde_String::length($line, 'utf-8'), null, 'utf-8');
            // Make sure didn't cut a word, unless we want hard breaks anyway.
            if (!$cut && preg_match('/^(.+?)(\s|\r?\n)/u', $string, $match)) {
                $line .= $match[1];
                $string = Horde_String::substr($string, Horde_String::length($match[1], 'utf-8'), null, 'utf-8');
            }
            // Wrap at existing line breaks.
            if (preg_match('/^(.*?)(\r?\n)(.*)$/u', $line, $match)) {
                $wrapped .= $match[1] . $match[2];
                $string = $match[3] . $string;
                continue;
            }
            // Wrap at the last colon or semicolon followed by a whitespace if
            // doing line folding.
            if ($line_folding &&
                preg_match('/^(.*?)(;|:)(\s+.*)$/u', $line, $match)
            ) {
                $wrapped .= $match[1] . $match[2] . $break;
                $string = $match[3] . $string;
                continue;
            }
            // Wrap at the last whitespace of $line.
            if ($line_folding) {
                $sub = '(.+[^\s])';
            } else {
                $sub = '(.*)';
            }
            if (preg_match('/^' . $sub . '(\s+)(.*)$/u', $line, $match)) {
                $wrapped .= $match[1] . $break;
                $string = ($line_folding ? $match[2] : '') . $match[3] . $string;
                continue;
            }
            // Hard wrap if necessary.
            if ($cut) {
                $wrapped .= Horde_String::substr($line, 0, $width, 'utf-8') . $break;
                $string = Horde_String::substr($line, $width, null, 'utf-8') . $string;
                continue;
            }
            $wrapped .= $line;
        }

        return Horde_String::convertCharset($wrapped . $string, 'utf-8', $charset);
    }

    /**
     * Wraps the text of a message.
     *
     * @param string $text Horde_String containing the text to wrap.
     * @param integer $length Wrap $text at this number of characters.
     * @param string $break_char Character(s) to use when breaking lines.
     * @param string $charset Character set to use when breaking lines.
     * @param boolean $quote Ignore lines that are wrapped with the '>'
     *                            character (RFC 2646)? If true, we don't
     *                            remove any padding whitespace at the end of
     *                            the string.
     *
     * @return string  Horde_String containing the wrapped text.
     */
    public function wrap($text, $length = 80, $break_char = "\n", $charset = null,
                         $quote = false)
    {
        $paragraphs = array();

        foreach (preg_split('/\r?\n/', $text) as $input) {
            if ($quote && (strpos($input, '>') === 0)) {
                $line = $input;
            } else {
                /* We need to handle the Usenet-style signature line
                 * separately; since the space after the two dashes is
                 * REQUIRED, we don't want to trim the line. */
                if ($input != '-- ') {
                    $input = rtrim($input);
                }
                $line = Horde_String::wordwrap($input, $length, $break_char, false, $charset);
            }

            $paragraphs[] = $line;
        }

        return implode($break_char, $paragraphs);
    }

    /**
     * Returns true if the every character in the parameter is an alphabetic
     * character.
     *
     * @param $string   The string to test.
     * @param $charset  The charset to use when testing the string.
     *
     * @return boolean  True if the parameter was alphabetic only.
     */
    public function isAlpha($string, $charset = null)
    {
        if (!Horde_String::extensionExists('mbstring')) {
            return ctype_alpha($string);
        }

        $charset = Horde_String::_mbstringCharset($charset);
        $old_charset = mb_regex_encoding();
        $old_error = error_reporting(0);

        if ($charset != $old_charset) {
            mb_regex_encoding($charset);
        }
        $alpha = !mb_ereg_match('[^[:alpha:]]', $string);
        if ($charset != $old_charset) {
            mb_regex_encoding($old_charset);
        }

        error_reporting($old_error);

        return $alpha;
    }

    /**
     * Returns true if ever character in the parameter is a lowercase letter in
     * the current locale.
     *
     * @param $string   The string to test.
     * @param $charset  The charset to use when testing the string.
     *
     * @return boolean  True if the parameter was lowercase.
     */
    public function isLower($string, $charset = null)
    {
        return ((Horde_String::lower($string, true, $charset) === $string) &&
            Horde_String::isAlpha($string, $charset));
    }

    /**
     * Returns true if every character in the parameter is an uppercase letter
     * in the current locale.
     *
     * @param string $string The string to test.
     * @param string $charset The charset to use when testing the string.
     *
     * @return boolean  True if the parameter was uppercase.
     */
    public function isUpper($string, $charset = null)
    {
        return ((Horde_String::upper($string, true, $charset) === $string) &&
            Horde_String::isAlpha($string, $charset));
    }

    /**
     * Performs a multibyte safe regex match search on the text provided.
     *
     * @since Horde 3.1
     *
     * @param string $text The text to search.
     * @param array $regex The regular expressions to use, without perl
     *                         regex delimiters (e.g. '/' or '|').
     * @param string $charset The character set of the text.
     *
     * @return array  The matches array from the first regex that matches.
     */
    public function regexMatch($text, $regex, $charset = null)
    {
        if (!empty($charset)) {
            $regex = Horde_String::convertCharset($regex, $charset, 'utf-8');
            $text = Horde_String::convertCharset($text, $charset, 'utf-8');
        }

        $matches = array();
        foreach ($regex as $val) {
            if (preg_match('/' . $val . '/u', $text, $matches)) {
                break;
            }
        }

        if (!empty($charset)) {
            $matches = Horde_String::convertCharset($matches, 'utf-8', $charset);
        }

        return $matches;
    }

    /**
     * Workaround charsets that don't work with mbstring public functions.
     *
     * @access private
     *
     * @param string $charset The original charset.
     *
     * @return string  The charset to use with mbstring public functions.
     */
    protected function _mbstringCharset($charset)
    {
        /* mbstring public functions do not handle the 'ks_c_5601-1987' &
         * 'ks_c_5601-1989' charsets. However, these charsets are used, for
         * example, by various versions of Outlook to send Korean characters.
         * Use UHC (CP949) encoding instead. See, e.g.,
         * http://lists.w3.org/Archives/Public/ietf-charsets/2001AprJun/0030.html */
        if (in_array(Horde_String::lower($charset), array('ks_c_5601-1987', 'ks_c_5601-1989'))) {
            $charset = 'UHC';
        }

        return $charset;
    }

}
