<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    private string $secret_skip_key;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
        $this->secret_skip_key = uniqid();
    }

    public const CHARS = [
        '?d',
        '?f',
        '?a',
        '?#',
        '?',
    ];

    public const VALIDATE_TYPES = [
        'boolean',
        'integer',
        'double',
        'string',
        'NULL',
    ];

    /**
     * @param $string
     * @return array
     */
    private function contains($string): array
    {
        $pregMatchPrepare = function (string $char) {
            return '(' . preg_quote($char) . ')';
        };

        $exp = '/' . implode('|', array_map($pregMatchPrepare, self::CHARS)) . '/';
        $char_exists = preg_match_all($exp, $string, $matches, PREG_OFFSET_CAPTURE);
        return [$matches, $char_exists];
    }

    /**
     * Validate type ?
     * @param $value
     * @return void
     * @throws Exception
     */
    private function validateDefaultTypeChar($value): void
    {
        $type_char = gettype($value);
        if (!in_array($type_char, self::VALIDATE_TYPES)) {
            throw new Exception("Value: $value is not type " . implode(', ', self::VALIDATE_TYPES));
        }
    }

    /**
     * @param array $array
     * @return string
     * @throws Exception
     */
    private function validateArray(array $array): string
    {
        array_walk($array, function (& $item) {
            $this->validateDefaultTypeChar($item);
            $item = $this->validateDefaultChar($item);
        });

        $getKeyArray = function ($array) {
            $result = [];
            foreach ($array as $key => $value) {
                $result[] = '`' . $key . '` = ' . $value;
            }

            return $result;
        };

        if (array_is_list($array)) {
            return implode(', ', $array);
        } else {
            return implode(', ', $getKeyArray($array));
        }
    }

    /**
     * @throws Exception
     */
    private function validateDefaultChar($value): mixed
    {
        $this->validateDefaultTypeChar($value);
        return match (gettype($value)) {
            'boolean' => (int)$value,
            'NULL' => $this->mysqli->real_escape_string("NULL"),
            'string' => $this->addQuote($value),
            default => $value,
        };
    }

    /**
     * @throws Exception
     */
    private function returnValidatedValue(string $char, $value): mixed
    {
        return match ($char) {
            self::CHARS[0] => gettype($value) === 'NULL' ? null : (int)$value,
            self::CHARS[1] => gettype($value) === 'NULL' ? null : (float)$value,
            self::CHARS[2] => $this->validateArray($value),
            self::CHARS[3] => is_array($value) ? implode(', ', array_map(fn($item) => $this->addQuote($item, '`'), $value)) : $this->addQuote($value, '`'),
            self::CHARS[4] => $this->validateDefaultChar($value),
            default => $value,
        };
    }

    /**
     * @param array $matches
     * @param array $args
     * @param string $string
     * @return string
     * @throws Exception
     */
    private function bindString(array $matches, array $args, string $string): string
    {
        $result = $string;

        foreach ($matches as $key => $match) {
            $result = preg_replace( '/' . preg_quote($match[0]) . '/', $this->returnValidatedValue($match[0], $args[$key]), $result, 1);
        }

        return $result;
    }

    /**
     * @param string $query
     * @param array $args
     * @return string
     * @throws Exception
     */
    public function buildQuery(string $query, array $args = []): string
    {
        $query_string = $this->getStringWithSkip($query, $args);

        list($matches, $char_exists) = $this->contains($query_string);
        if ($char_exists) {
            $string =  $this->bindString($matches[0], $args, $query_string);
        } else {
            $string = $query_string;
        }

        return $this->mysqli->real_escape_string($string);
    }

    public function skip(): string
    {
        return $this->secret_skip_key;
    }

    /**
     * @param string $string
     * @param array $args
     * @return array|string|string[]|null
     */
    public function getStringWithSkip(string $string, array $args): array|string|null
    {
        if (in_array($this->secret_skip_key, $args, true)) {
            return preg_replace('/{((?>[^{}]+|(?R))*)}/', '', $string);
        } else {
            return preg_replace('/[{}]/','', $string);
        }
    }

    /**
     * @param string $value
     * @param string $quote
     * @return string
     */
    private function addQuote(string $value, string $quote = "'")
    {
        return $quote . $value . $quote;
    }
}
