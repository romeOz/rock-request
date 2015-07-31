<?php
namespace rock\request;

use rock\base\ObjectInterface;
use rock\base\ObjectTrait;
use rock\helpers\Json;

/**
 * Parses a raw HTTP request using {\rock\helpers\Json::decode()}
 *
 * To enable parsing for JSON requests you can configure {@see \tock\request\Request::$parsers} using this class:
 *
 * ```php
 * 'request' => [
 *     'parsers' => [
 *         'application/json' => 'rock\request\JsonParser',
 *     ]
 * ]
 * ```
 */
class JsonParser implements RequestParserInterface, ObjectInterface
{
    use ObjectTrait;

    /**
     * @var boolean whether to return objects in terms of associative arrays.
     */
    public $asArray = true;
    /**
     * @var boolean whether to throw a {@see \rock\request\RequestException} if the body is invalid json.
     */
    public $throwException = true;


    /**
     * Parses a HTTP request body.
     * @param string $rawBody the raw HTTP request body.
     * @param string $contentType the content type specified for the request body.
     * @return array parameters parsed from the request body
     * @throws RequestException
     */
    public function parse($rawBody, $contentType)
    {
        try {
            return Json::decode($rawBody, $this->asArray);
        } catch (\Exception $e) {
            if ($this->throwException) {
                throw new RequestException('Invalid JSON data in request body: ' . $e->getMessage(), [], $e);
            }

            return null;
        }
    }
}
