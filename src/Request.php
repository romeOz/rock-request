<?php
namespace rock\request;

use rock\base\Alias;
use rock\base\BaseException;
use rock\base\ObjectInterface;
use rock\base\ObjectTrait;
use rock\helpers\ArrayHelper;
use rock\helpers\Helper;
use rock\helpers\Instance;
use rock\log\Log;
use rock\sanitize\Attributes;
use rock\sanitize\Sanitize;

/**
 * The web Request class represents an HTTP request
 *
 * @property string $absoluteUrl The currently requested absolute URL. This property is read-only.
 * @property array $acceptableContentTypes The content types ordered by the quality score. Types with the
 * highest scores will be returned first. The array keys are the content types, while the array values are the
 * corresponding quality score and other parameters as given in the header.
 * @property string $scheme
 * @property integer $port Port number for insecure requests.
 * @property integer $securePort Port number for secure requests.
 * @property string $serverName Server name. This property is read-only.
 * @property integer $serverPort Server port number. This property is read-only.
 * @property string $host hostname part  (e.g. `www.site.com`).
 * @property-read string $hostInfo Schema and hostname part (with port number if needed) of the request URL (e.g.
 * `http://www.site.com`).
 * @property string $queryString Part of the request URL that is after the question mark. This property is
 * read-only.
 * @property string $url The currently requested relative URL. Note that the URI returned is URL-encoded.
 * @property string $baseUrl The relative URL for the application.
 * @property string $homeUrl
 * @property string $referrer URL referrer, null if not present. This property is read-only.
 * @property string $scriptFile The entry script file path.
 * @property string $scriptUrl The relative URL of the entry script.
 * @property string $userAgent User agent, null if not present. This property is read-only.
 * @property string $userHost User host name, null if cannot be determined. This property is read-only.
 * @property string $userIP User IP address. Null is returned if the user IP address cannot be detected. This
 * property is read-only.
 * @property-read array $eTags The entity tags. This property is read-only.
 * @property string $rawBody The request body. This property is read-only.
 * @property array $bodyParams The request parameters given in the request body.
 * @property array $acceptableLanguages The languages ordered by the preference level. The first element
 * represents the most preferred language.
 *
 * @method static mixed get($name = null, $default = null, Sanitize $sanitize = null)
 * @method static mixed post($name = null, $default = null, Sanitize $sanitize = null)
 * @method static mixed rawGet($name = null, $default = null)
 * @method static mixed rawPost($name = null, $default = null)
 *
 * @package rock\request
 */
class Request implements RequestInterface, ObjectInterface
{
    use ObjectTrait {
        ObjectTrait::__call as parentCall;
    }

    const DEFAULT_LOCALE = 'en';

    /**
     * Checking referrer on allow domains
     * @var array
     */
    public $allowDomains = [];
    /**
     * @var string|boolean the name of the POST parameter that is used to indicate if a request is a `PUT`, `PATCH` or `DELETE`
     * request tunneled through POST. Default to '_method'.
     * @see getMethod()
     */
    public $methodParam = '_method';
    /**
     * @var array the parsers for converting the raw HTTP request body into {@see \rock\request\Request::$bodyParams}.
     * The array keys are the request `Content-Types`, and the array values are the
     * corresponding configurations for {@see \rock\helpers\Instance::ensure()} creating the parser objects.
     * A parser must implement the {@see \rock\request\RequestParserInterface}.
     *
     * To enable parsing for JSON requests you can use the {@see \rock\request\JsonParser} class like in the following example:
     *
     * ```php
     * [
     *     'application/json' => 'rock\request\JsonParser',
     * ]
     * ```
     *
     * To register a parser for parsing all request types you can use `'*'` as the array key.
     * This one will be used as a fallback in case no other types match.
     *
     * @see getBodyParams()
     */
    public $parsers = [];
    /**
     * @var boolean whether to show entry script name in the constructed URL. Defaults to true.
     */
    public $showScriptName = true;
    /**
     * Default sanitize rules.
     * @var Sanitize
     */
    public $sanitize;

    public function init()
    {
        $this->isSelfDomain(true);
    }

    private $_method;

    /**
     * Returns the method of the current request (e.g. `GET`, `POST`, `HEAD`, `PUT`, `PATCH`, `DELETE`).
     *
     * @return string request method, such as `GET`, `POST`, `HEAD`, `PUT`, `PATCH`, `DELETE`.
     * The value returned is turned into upper case.
     */
    public function getMethod()
    {
        if (isset($this->_method)) {
            return $this->_method;
        }
        if (isset($_POST[$this->methodParam])) {
            return $this->_method = strtoupper($_POST[$this->methodParam]);
        } elseif (isset($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'])) {
            return $this->_method = strtoupper($_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE']);
        } else {
            return $this->_method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        }
    }

    /**
     * Sets a http-method.
     * @param string $httpMethod request method, such as `GET`, `POST`, `HEAD`, `PUT`, `PATCH`, `DELETE`.
     * @return $this
     */
    public function setMethod($httpMethod = 'GET')
    {
        $this->_method = $httpMethod;
        return $this;
    }

    /**
     * Is methods request.
     *
     * @param array $methods names of methods
     * @return bool
     */
    public function isMethods(array $methods)
    {
        return in_array($this->getMethod(), $methods, true);
    }

    /**
     * @var string
     */
    private $_schema;

    /**
     * Returns schema.
     *
     * @return string
     */
    public function getScheme()
    {
        if ($this->_schema === null) {
            $this->_schema = $this->isSecureConnection() ? 'https' : 'http';
        }

        return $this->_schema;
    }

    /**
     * Sets a scheme.
     * @param string $scheme
     * @return $this
     */
    public function setScheme($scheme)
    {
        $this->_schema = $scheme;
        return $this;
    }

    private $_hostInfo;

    /**
     * Returns the schema and host part of the current request URL.
     *
     * The returned URL does not have an ending slash.
     * By default this is determined based on the user request information.
     * You may explicitly specify it by setting the {@see \rock\request\Request::$hostInfo} property.
     * @return string schema and hostname part (with port number if needed) of the request URL (e.g. `http://www.site.com`)
     */
    public function getHostInfo()
    {
        if ($this->_hostInfo === null) {
            $secure = $this->isSecureConnection();
            $http = $secure ? 'https' : 'http';
            if (isset($_SERVER['HTTP_HOST'])) {
                $this->_hostInfo = $http . '://' . $this->getHost();
            } elseif (isset($_SERVER['SERVER_NAME'])) {
                $this->_hostInfo = $http . '://' . $this->getHost();
                $port = $secure ? $this->getSecurePort() : $this->getPort();
                if (($port !== 80 && !$secure) || ($port !== 443 && $secure)) {
                    $this->_hostInfo .= ':' . $port;
                }
            } else {
                $this->_hostInfo = null;
            }
        }

        return $this->_hostInfo;
    }

    private $_host;

    public function getHost()
    {
        if ($this->_host === null && isset($_SERVER['SERVER_NAME'])) {
            $this->_host = Helper::getValue($_SERVER['HTTP_HOST'], $_SERVER['SERVER_NAME']);
        }

        return $this->_host;
    }

    /**
     * Sets a host.
     * @param string $host
     * @return $this
     */
    public function setHost($host)
    {
        $this->_host = $host;
        return $this;
    }

    private $_url;

    /**
     * Returns the currently requested relative URL.
     *
     * This refers to the portion of the URL that is after the {@see \rock\request\Request::getHostInfo()} part.
     * It includes the {@see \rock\request\Request::getQueryString()} part if any.
     *
     * @return string the currently requested relative URL. Note that the URI returned is URL-encoded.
     * @throws RequestException if the URL cannot be determined due to unusual server configuration
     */
    public function getUrl()
    {
        if ($this->_url === null) {
            $this->_url = $this->resolveRequestUri();
        }
        return $this->_url;
    }

    /**
     * Sets the currently requested relative URL.
     *
     * The URI must refer to the portion that is after {@see \rock\request\Request::getHostInfo()}.
     * Note that the URI should be URL-encoded.
     * @param string $url the request URI to be set
     * @return $this
     */
    public function setUrl($url)
    {
        $this->_url = $url;
        return $this;
    }

    /**
     * Returns the currently requested absolute URL.
     *
     * This is a shortcut to the concatenation of {@see \rock\request\Request::getHostInfo()} and {@see \rock\request\Request::getUrl()}.
     *
     * @param bool $strip
     * @return string the currently requested absolute URL.
     */
    public function getAbsoluteUrl($strip = true)
    {
        $url = $this->getHostInfo() . $this->getUrl();
        return $strip === true ? strip_tags($url) : $url;
    }

    /**
     * Returns path.
     *
     * ```
     * http://site.com/foo/
     * ```
     *
     * @return string
     */
    public function getUrlWithoutArgs()
    {
        $url = $this->getUrl();
        if (($pos = strpos($url, '?')) !== false) {
            $url = substr($url, 0, $pos);
        }
        return $url;
    }

    private $_homeUrl;

    /**
     * @return string the homepage URL
     */
    public function getHomeUrl()
    {
        if ($this->_homeUrl === null) {
            if ($this->showScriptName) {
                return $this->getScriptUrl();
            } else {
                return $this->getBaseUrl() . '/';
            }
        } else {
            return Alias::getAlias($this->_homeUrl);
        }
    }

    /**
     * Sets a home url.
     * @param string $url the homepage URL
     * @return $this
     */
    public function setHomeUrl($url)
    {
        $this->_homeUrl = $url;
        return $this;
    }

    private $_baseUrl;


    /**
     * Returns the relative URL for the application.
     *
     * This is similar to {@see \rock\request\Request::getScriptUrl()} except that it does not include the script file name,
     * and the ending slashes are removed.
     * @return string the relative URL for the application
     * @see setScriptUrl()
     */
    public function getBaseUrl()
    {
        if ($this->_baseUrl === null) {
            $this->_baseUrl = rtrim(dirname($this->getScriptUrl()), '\\/');
        }
        return $this->_baseUrl;
    }

    /**
     * Sets the relative URL for the application.
     *
     * By default the URL is determined based on the entry script URL.
     * This setter is provided in case you want to change this behavior.
     * @param string $value the relative URL for the application
     * @return $this
     */
    public function setBaseUrl($value)
    {
        $this->_baseUrl = $value;
        return $this;
    }

    private $_scriptUrl;

    /**
     * Returns the relative URL of the entry script.
     *
     * The implementation of this method referenced Zend_Controller_Request_Http in Zend Framework.
     * @return string the relative URL of the entry script.
     * @throws \Exception if unable to determine the entry script URL
     */
    public function getScriptUrl()
    {
        if ($this->_scriptUrl === null) {
            $scriptFile = $this->getScriptFile();
            $scriptName = basename($scriptFile);
            if (basename($_SERVER['SCRIPT_NAME']) === $scriptName) {
                $this->_scriptUrl = $_SERVER['SCRIPT_NAME'];
            } elseif (basename($_SERVER['PHP_SELF']) === $scriptName) {
                $this->_scriptUrl = $_SERVER['PHP_SELF'];
            } elseif (isset($_SERVER['ORIG_SCRIPT_NAME']) && basename($_SERVER['ORIG_SCRIPT_NAME']) === $scriptName) {
                $this->_scriptUrl = $_SERVER['ORIG_SCRIPT_NAME'];
            } elseif (($pos = strpos($_SERVER['PHP_SELF'], '/' . $scriptName)) !== false) {
                $this->_scriptUrl = substr($_SERVER['SCRIPT_NAME'], 0, $pos) . '/' . $scriptName;
            } elseif (!empty($_SERVER['DOCUMENT_ROOT']) && strpos($scriptFile, $_SERVER['DOCUMENT_ROOT']) === 0) {
                $this->_scriptUrl = str_replace('\\', '/', str_replace($_SERVER['DOCUMENT_ROOT'], '', $scriptFile));
            } else {
                throw new \Exception('Unable to determine the entry script URL.');
            }
        }
        return $this->_scriptUrl;
    }

    /**
     * Sets the relative URL for the application entry script.
     *
     * This setter is provided in case the entry script URL cannot be determined
     * on certain Web servers.
     * @param string $value the relative URL for the application entry script.
     * @return $this
     */
    public function setScriptUrl($value)
    {
        $this->_scriptUrl = '/' . trim($value, '/');
        return $this;
    }

    private $_scriptFile;


    /**
     * Returns the entry script file path.
     *
     * The default implementation will simply return `$_SERVER['SCRIPT_FILENAME']`.
     * @return string the entry script file path
     */
    public function getScriptFile()
    {
        return isset($this->_scriptFile) ? $this->_scriptFile : $_SERVER['SCRIPT_FILENAME'];
    }

    private $_port;

    /**
     * Returns the port to use for insecure requests.
     *
     * Defaults to 80, or the port specified by the server if the current
     * request is insecure.
     * @return integer port number for insecure requests.
     * @see setPort()
     */
    public function getPort()
    {
        if ($this->_port === null) {
            $this->_port = !$this->isSecureConnection() && isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 80;
        }
        return $this->_port;
    }

    /**
     * Sets the port to use for insecure requests.
     * This setter is provided in case a custom port is necessary for certain
     * server configurations.
     * @param integer $port port number.
     * @return $this
     */
    public function setPort($port)
    {
        if ($port != $this->_port) {
            $this->_port = (int)$port;
            $this->_hostInfo = null;
        }
        return $this;
    }

    private $_securePort;

    /**
     * Returns the port to use for secure requests.
     *
     * Defaults to 443, or the port specified by the server if the current
     * request is secure.
     * @return integer port number for secure requests.
     * @see setSecurePort()
     */
    public function getSecurePort()
    {
        if ($this->_securePort === null) {
            $this->_securePort = $this->isSecureConnection() && isset($_SERVER['SERVER_PORT']) ? (int)$_SERVER['SERVER_PORT'] : 443;
        }
        return $this->_securePort;
    }

    /**
     * Sets the port to use for secure requests.
     *
     * This setter is provided in case a custom port is necessary for certain
     * server configurations.
     * @param integer $value port number.
     * @return $this
     */
    public function setSecurePort($value)
    {
        if ($value != $this->_securePort) {
            $this->_securePort = (int)$value;
            $this->_hostInfo = null;
        }
        return $this;
    }

    private $_queryParams;

    /**
     * Returns the request parameters given in the {@see \rock\request\Request::$queryString}.
     *
     * This method will return the contents of `$_GET` if params where not explicitly set.
     * @return array the request GET parameter values.
     * @see setRawQueryParams()
     */
    public function getQueryParams()
    {
        if (isset($this->_queryParams)) {
            return $this->_queryParams;
        }

        if ($queryString = $this->getQueryString()) {
            $result = [];
            parse_str($queryString, $result);
            return $this->_queryParams = $result;
        }

        return $this->_queryParams;
    }

    /**
     * Sets the request {@see \rock\request\Request::$queryString} parameters.
     * @param array $params the request query parameters (name-value pairs)
     * @see getRawQueryParam()
     * @see getRawQueryParams()
     * @return $this
     */
    public function setQueryParams($params)
    {
        $this->_queryParams = $params;
        return $this;
    }

    /**
     * Returns the named GET parameter value.
     * If the GET parameter does not exist, the second parameter passed to this method will be returned.
     * @param string $name the GET parameter name.
     * @param mixed $default the default parameter value if the GET parameter does not exist.
     * @return mixed the GET parameter value
     * @see getBodyParam()
     */
    public function getQueryParam($name, $default = null)
    {
        return ArrayHelper::getValue($this->getQueryParams(), $name, $default);
    }

    /**
     * @var string
     */
    private $_queryString;

    /**
     * Returns part of the request URL that is after the question mark.
     *
     * @return string part of the request URL that is after the question mark
     */
    public function getQueryString()
    {
        if (isset($this->_queryString)) {
            return $this->_queryString;
        }
        return $this->_queryString = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
    }

    /**
     * Sets a part of the request URL.
     * @param $queryString
     * @return $this
     */
    public function setQueryString($queryString)
    {
        $this->_queryString = $queryString;
        return $this;
    }

    private $_rawBody;

    /**
     * Returns the raw HTTP request body.
     * @return string the request body
     */
    public function getRawBody()
    {
        if ($this->_rawBody === null) {
            $this->_rawBody = trim(file_get_contents('php://input'));
        }

        return $this->_rawBody;
    }

    /**
     * Sets the raw HTTP request body, this method is mainly used by test scripts to simulate raw HTTP requests.
     * @param $rawBody
     * @return $this
     */
    public function setRawBody($rawBody)
    {
        $this->_rawBody = $rawBody;
        return $this;
    }

    private $_bodyParams;

    /**
     * Returns the request parameters given in the request body.
     *
     * Request parameters are determined using the parsers configured in {@see \rock\request\Request::$parsers} property.
     * If no parsers are configured for the current {@see \rock\request\Request::$contentType} it uses the PHP function `mb_parse_str()`
     * to parse the {@see \rock\request\Request::$rawBody} (request body}.
     * @return array the request parameters given in the request body.
     * @throws RequestException
     * @see getMethod()
     * @see getBodyParam()
     * @see setBodyParams()
     */
    public function getBodyParams()
    {
        if (isset($this->_bodyParams)) {
            return $this->_bodyParams;
        }

        if (isset($_POST[$this->methodParam])) {
            $this->_bodyParams = $_POST;
            unset($this->_bodyParams[$this->methodParam]);
            return $this->_bodyParams;
        }

        $contentType = $this->getContentType();
        if (($pos = strpos($contentType, ';')) !== false) {
            // e.g. application/json; charset=UTF-8
            $contentType = substr($contentType, 0, $pos);
        }

        if (isset($this->parsers[$contentType])) {
            $parser = Instance::ensure($this->parsers[$contentType], null, [], false);
            if (!($parser instanceof RequestParserInterface)) {
                throw new RequestException("The '{$contentType}' request parser is invalid. It must implement the rock\\request\\RequestParserInterface.");
            }
            $this->_bodyParams = $parser->parse($this->getRawBody(), $contentType);
        } elseif (isset($this->parsers['*'])) {
            $parser = Instance::ensure($this->parsers['*'], null, [], false);
            if (!($parser instanceof RequestParserInterface)) {
                throw new RequestException("The fallback request parser is invalid. It must implement the rock\\request\\RequestParserInterface.");
            }
            $this->_bodyParams = $parser->parse($this->getRawBody(), $contentType);
        } elseif ($this->getMethod() === 'POST') {
            // PHP has already parsed the body so we have all params in $_POST
            $this->_bodyParams = $_POST;
        } else {
            mb_parse_str($this->getRawBody(), $this->_bodyParams);
        }

        return $this->_bodyParams;
    }

    /**
     * Sets a body params.
     * @param array $params
     * @return $this
     */
    public function setBodyParams(array $params)
    {
        $this->_bodyParams = $params;
        return $this;
    }

    private $_contentTypes;

    /**
     * Returns the content types acceptable by the end user.
     *
     * This is determined by the `Accept` HTTP header. For example,
     *
     * ```php
     * $_SERVER['HTTP_ACCEPT'] = 'text/plain; q=0.5, application/json; version=1.0, application/xml; version=2.0;';
     * $types = $request->getAcceptableContentTypes();
     * print_r($types);
     * // displays:
     * // [
     * //     'application/json' => ['q' => 1, 'version' => '1.0'],
     * //      'application/xml' => ['q' => 1, 'version' => '2.0'],
     * //           'text/plain' => ['q' => 0.5],
     * // ]
     * ```
     *
     * @return array the content types ordered by the quality score. Types with the highest scores
     * will be returned first. The array keys are the content types, while the array values
     * are the corresponding quality score and other parameters as given in the header.
     */
    public function getAcceptableContentTypes()
    {
        if ($this->_contentTypes === null) {
            if (isset($_SERVER['HTTP_ACCEPT'])) {
                $this->_contentTypes = $this->parseAcceptHeader($_SERVER['HTTP_ACCEPT']);
            } else {
                $this->_contentTypes = [];
            }
        }

        return $this->_contentTypes;
    }

    /**
     * Sets the acceptable content types.
     *
     * Please refer to {@see \rock\request\Request::getAcceptableContentTypes()} on the format of the parameter.
     * @param array $value the content types that are acceptable by the end user. They should
     * be ordered by the preference level.
     * @see \rock\request\Request::getAcceptableContentTypes()
     * @see parseAcceptHeader()
     * @return $this
     */
    public function setAcceptableContentTypes($value)
    {
        $this->_contentTypes = $value;
        return $this;
    }

    private $_contentType;

    /**
     * Returns request content-type.
     *
     * The Content-Type header field indicates the MIME type of the data
     * contained in the case of the HEAD method, the
     * media type that would have been sent had the request been a GET.
     * For the MIME-types the user expects in response, see {@see \rock\request\Request::getAcceptableContentTypes()} .
     * @return string request content-type. Null is returned if this information is not available.
     * @link http://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.17
     * HTTP 1.1 header field definitions
     */
    public function getContentType()
    {
        if (isset($this->_contentType)) {
            return $this->_contentType;
        }
        if (isset($_SERVER["CONTENT_TYPE"])) {
            return $this->_contentType = $_SERVER["CONTENT_TYPE"];
        } elseif (isset($_SERVER["HTTP_CONTENT_TYPE"])) {
            //fix bug https://bugs.php.net/bug.php?id=66606
            return $this->_contentType = $_SERVER["HTTP_CONTENT_TYPE"];
        }

        return null;
    }

    /**
     * Sets a content type.
     * @param string $contentType
     * @return $this
     */
    public function setContentType($contentType)
    {
        $this->_contentType = $contentType;
        return $this;
    }

    private $_languages;

    /**
     * Returns the languages acceptable by the end user.
     * This is determined by the `Accept-Language` HTTP header.
     * @return array the languages ordered by the preference level. The first element
     * represents the most preferred language.
     */
    public function getAcceptableLanguages()
    {
        if ($this->_languages === null) {
            if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
                $this->_languages = array_keys(static::parseAcceptHeader($_SERVER['HTTP_ACCEPT_LANGUAGE']));
            } else {
                $this->_languages = [];
            }
        }

        return $this->_languages;
    }

    /**
     * @param array $value the languages that are acceptable by the end user. They should
     * be ordered by the preference level.
     * @return $this
     */
    public function setAcceptableLanguages($value)
    {
        $this->_languages = $value;
        return $this;
    }

    /**
     * Parses the given `Accept` (or `Accept-Language`) header.
     *
     * This method will return the acceptable values with their quality scores and the corresponding parameters
     * as specified in the given `Accept` header. The array keys of the return value are the acceptable values,
     * while the array values consisting of the corresponding quality scores and parameters. The acceptable
     * values with the highest quality scores will be returned first. For example,
     *
     * ```php
     * $header = 'text/plain; q=0.5, application/json; version=1.0, application/xml; version=2.0;';
     * $accepts = $request->parseAcceptHeader($header);
     * print_r($accepts);
     * // displays:
     * // [
     * //     'application/json' => ['q' => 1, 'version' => '1.0'],
     * //      'application/xml' => ['q' => 1, 'version' => '2.0'],
     * //           'text/plain' => ['q' => 0.5],
     * // ]
     * ```
     *
     * @param string $header the header to be parsed
     * @return array the acceptable values ordered by their quality score. The values with the highest scores
     * will be returned first.
     */
    public function parseAcceptHeader($header)
    {
        $accepts = [];
        foreach (explode(',', $header) as $i => $part) {
            $params = preg_split('/\s*;\s*/', trim($part), -1, PREG_SPLIT_NO_EMPTY);
            if (empty($params)) {
                continue;
            }
            $values = [
                'q' => [$i, array_shift($params), 1],
            ];
            foreach ($params as $param) {
                if (strpos($param, '=') !== false) {
                    list ($key, $value) = explode('=', $param, 2);
                    if ($key === 'q') {
                        $values['q'][2] = (double)$value;
                    } else {
                        $values[$key] = $value;
                    }
                } else {
                    $values[] = $param;
                }
            }
            $accepts[] = $values;
        }

        usort($accepts, function ($a, $b) {
            $a = $a['q']; // index, name, q
            $b = $b['q'];
            if ($a[2] > $b[2]) {
                return -1;
            } elseif ($a[2] < $b[2]) {
                return 1;
            } elseif ($a[1] === $b[1]) {
                return $a[0] > $b[0] ? 1 : -1;
            } elseif ($a[1] === '*/*') {
                return 1;
            } elseif ($b[1] === '*/*') {
                return -1;
            } else {
                $wa = $a[1][strlen($a[1]) - 1] === '*';
                $wb = $b[1][strlen($b[1]) - 1] === '*';
                if ($wa xor $wb) {
                    return $wa ? 1 : -1;
                } else {
                    return $a[0] > $b[0] ? 1 : -1;
                }
            }
        });

        $result = [];
        foreach ($accepts as $accept) {
            $name = $accept['q'][1];
            $accept['q'] = $accept['q'][2];
            $result[$name] = $accept;
        }

        return $result;
    }

    /**
     * Returns the user-preferred language that should be used by this application.
     * The language resolution is based on the user preferred languages and the languages
     * supported by the application. The method will try to find the best match.
     * @param array $languages a list of the languages supported by the application. If this is empty, the current
     * application language will be returned without further processing.
     * @return string the language that the application should use.
     */
    public function getPreferredLanguage(array $languages = [])
    {
        if (empty($languages)) {
            return self::DEFAULT_LOCALE;
        }
        foreach (static::getAcceptableLanguages() as $acceptableLanguage) {
            $acceptableLanguage = str_replace('_', '-', strtolower($acceptableLanguage));
            foreach ($languages as $language) {
                $normalizedLanguage = str_replace('_', '-', strtolower($language));

                if ($normalizedLanguage === $acceptableLanguage || // en-us==en-us
                    strpos($acceptableLanguage, $normalizedLanguage . '-') === 0 || // en==en-us
                    strpos($normalizedLanguage, $acceptableLanguage . '-') === 0
                ) { // en-us==en

                    return $language;
                }
            }
        }

        return reset($languages);
    }

    /**
     * Gets the Etags.
     *
     * @return array The entity tags
     */
    public function getETags()
    {
        if (isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            return preg_split('/[\s,]+/', str_replace('-gzip', '', $_SERVER['HTTP_IF_NONE_MATCH']), -1, PREG_SPLIT_NO_EMPTY);
        } else {
            return [];
        }
    }

    private $_pathInfo;

    /**
     * Returns the path info of the currently requested URL.
     *
     * A path info refers to the part that is after the entry script and before the question mark (query string).
     * The starting and ending slashes are both removed.
     *
     * @return string part of the request URL that is after the entry script and before the question mark.
     * Note, the returned path info is already URL-decoded.
     * @throws RequestException if the path info cannot be determined due to unexpected server configuration
     */
    public function getPathInfo()
    {
        if ($this->_pathInfo === null) {
            $this->_pathInfo = $this->resolvePathInfo();
        }

        return $this->_pathInfo;
    }

    /**
     * Sets the path info of the current request.
     *
     * This method is mainly provided for testing purpose.
     * @param string $value the path info of the current request
     * @return $this
     */
    public function setPathInfo($value)
    {
        $this->_pathInfo = ltrim($value, '/');
        return $this;
    }

    /**
     * Resolves the path info part of the currently requested URL.
     *
     * A path info refers to the part that is after the entry script and before the question mark (query string).
     * The starting slashes are both removed (ending slashes will be kept).
     *
     * @return string part of the request URL that is after the entry script and before the question mark.
     * Note, the returned path info is decoded.
     * @throws RequestException if the path info cannot be determined due to unexpected server configuration
     */
    protected function resolvePathInfo()
    {
        $pathInfo = $this->getUrl();

        if (($pos = strpos($pathInfo, '?')) !== false) {
            $pathInfo = substr($pathInfo, 0, $pos);
        }

        $pathInfo = urldecode($pathInfo);

        // try to encode in UTF8 if not so
        // http://w3.org/International/questions/qa-forms-utf-8.html
        if (!preg_match('%^(?:
            [\x09\x0A\x0D\x20-\x7E]              # ASCII
            | [\xC2-\xDF][\x80-\xBF]             # non-overlong 2-byte
            | \xE0[\xA0-\xBF][\x80-\xBF]         # excluding overlongs
            | [\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}  # straight 3-byte
            | \xED[\x80-\x9F][\x80-\xBF]         # excluding surrogates
            | \xF0[\x90-\xBF][\x80-\xBF]{2}      # planes 1-3
            | [\xF1-\xF3][\x80-\xBF]{3}          # planes 4-15
            | \xF4[\x80-\x8F][\x80-\xBF]{2}      # plane 16
            )*$%xs', $pathInfo)
        ) {
            $pathInfo = utf8_encode($pathInfo);
        }

        $scriptUrl = $this->getScriptUrl();
        $baseUrl = $this->getBaseUrl();
        if (strpos($pathInfo, $scriptUrl) === 0) {
            $pathInfo = substr($pathInfo, strlen($scriptUrl));
        } elseif ($baseUrl === '' || strpos($pathInfo, $baseUrl) === 0) {
            $pathInfo = substr($pathInfo, strlen($baseUrl));
        } elseif (isset($_SERVER['PHP_SELF']) && strpos($_SERVER['PHP_SELF'], $scriptUrl) === 0) {
            $pathInfo = substr($_SERVER['PHP_SELF'], strlen($scriptUrl));
        } else {
            throw new RequestException('Unable to determine the path info of the current request.');
        }

        if ($pathInfo[0] === '/') {
            $pathInfo = substr($pathInfo, 1);
        }

        return (string)$pathInfo;
    }

    /**
     * Is self domain.
     *
     * @param bool $throw throw an exception (default: false)
     * @throws RequestException
     * @return bool
     */
    public function isSelfDomain($throw = false)
    {
        if (!$domains = $this->allowDomains) {
            return true;
        }

        if (!in_array(strtolower($_SERVER['SERVER_NAME']), $domains, true) ||
            !in_array(strtolower($_SERVER['HTTP_HOST']), $domains, true)
        ) {
            if ($throw === true) {
                throw new RequestException("Invalid domain: {$_SERVER['HTTP_HOST']}");
            } else {
                if (class_exists('\rock\log\Log')) {
                    $message = BaseException::convertExceptionToString(new RequestException("Invalid domain: {$_SERVER['HTTP_HOST']}"));
                    Log::err($message);
                }
            }

            return false;
        }

        return true;
    }

    /**
     * Resolves the request URI portion for the currently requested URL.
     *
     * This refers to the portion that is after the {@see \rock\request\Request::$hostInfo} part. It includes
     * the @see queryString part if any.
     * The implementation of this method referenced Zend_Controller_Request_Http in Zend Framework.
     *
     * @return string|boolean the request URI portion for the currently requested URL.
     * Note that the URI returned is URL-encoded.
     * @throws RequestException if the request URI cannot be determined due to unusual server configuration
     */
    protected function resolveRequestUri()
    {
        if (isset($_SERVER['HTTP_X_REWRITE_URL'])) { // IIS
            $requestUri = $_SERVER['HTTP_X_REWRITE_URL'];
        } elseif (isset($_SERVER['REQUEST_URI'])) {
            $requestUri = $_SERVER['REQUEST_URI'];
            if ($requestUri !== '' && $requestUri[0] !== '/') {
                $requestUri = preg_replace('/^(http|https):\/\/[^\/]+/i', '', $requestUri);
            }
        } elseif (isset($_SERVER['ORIG_PATH_INFO'])) { // IIS 5.0 CGI
            $requestUri = $_SERVER['ORIG_PATH_INFO'];
            if ($query = $this->getQueryString()) {
                $requestUri .= '?' . $query;
            }
        } else {
            throw new RequestException('Unable to determine the request URI.');
        }
        return $requestUri;
    }

    /**
     * Return if the request is sent via secure channel (https).
     *
     * @return boolean if the request is sent via secure channel (https)
     */
    public function isSecureConnection()
    {
        return isset($_SERVER['HTTPS']) && (strcasecmp($_SERVER['HTTPS'], 'on') === 0 || $_SERVER['HTTPS'] == 1)
        || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strcasecmp($_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0;
    }

    /**
     * Returns the server name.
     *
     * @return string server name
     */
    public function getServerName()
    {
        return $_SERVER['SERVER_NAME'];
    }

    /**
     * Returns the server port number.
     *
     * @return integer server port number
     */
    public function getServerPort()
    {
        return (int)$_SERVER['SERVER_PORT'];
    }

    private $_referrer;

    /**
     * Returns the URL referrer, null if not present.
     *
     * @return string URL referrer, null if not present
     */
    public function getReferrer()
    {
        if (isset($this->_referrer)) {
            return $this->_referrer;
        }
        return $this->_referrer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;
    }

    /**
     * Sets a referrer url.
     * @param string $referrer
     * @return $this
     */
    public function setReferrer($referrer)
    {
        $this->_referrer = $referrer;
        return $this;
    }

    private $_userAgent;

    /**
     * Returns the user agent, null if not present.
     *
     * @return string user agent, null if not present
     */
    public function getUserAgent()
    {
        if (isset($this->_userAgent)) {
            return $this->_userAgent;
        }
        return $this->_userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
    }

    /**
     * Sets a user agent.
     * @param string $userAgent
     * @return $this
     */
    public function setUserAgent($userAgent)
    {
        $this->_userAgent = $userAgent;
        return $this;
    }

    /**
     * User IP address.
     * @var string
     */
    private $_userIP;

    /**
     * Returns the user IP address.
     * @return string user IP address
     */
    public function getUserIP()
    {
        if (isset($this->_userIP)) {
            return $this->_userIP;
        }
        return $this->_userIP = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '127.0.0.1';
    }

    /**
     * Sets a user IP address.
     * @param string $ip
     * @return $this
     */
    public function setUserIP($ip)
    {
        $this->_userIP = $ip;
        return $this;
    }

    /**
     * User host name.
     * @var string
     */
    private $_userHost;

    /**
     * Returns the user host name, null if it cannot be determined.
     *
     * @return string user host name, null if cannot be determined
     */
    public function getUserHost()
    {
        if (isset($this->_userHost)) {
            return $this->_userHost;
        }
        return $this->_userHost = isset($_SERVER['REMOTE_HOST']) ? $_SERVER['REMOTE_HOST'] : null;
    }

    /**
     * Sets a user host name.
     * @param string $host
     * @return $this
     */
    public function setUserHost($host)
    {
        $this->_userHost = $host;
        return $this;
    }

    /**
     * @return string the username sent via HTTP authentication, null if the username is not given
     */
    public function getAuthUser()
    {
        return isset($_SERVER['PHP_AUTH_USER']) ? $_SERVER['PHP_AUTH_USER'] : null;
    }

    /**
     * @return string the password sent via HTTP authentication, null if the password is not given
     */
    public function getAuthPassword()
    {
        return isset($_SERVER['PHP_AUTH_PW']) ? $_SERVER['PHP_AUTH_PW'] : null;
    }

    /**
     * Is ips request.
     * @param array $ips ips
     * @return bool
     */
    public function isIps(array $ips)
    {
        return in_array($this->getUserIP(), $ips, true);
    }

    /**
     * Returns whether this is a GET request.
     *
     * @return boolean whether this is a GET request.
     */
    public function isGet()
    {
        return $this->getMethod() === 'GET';
    }

    /**
     * Returns whether this is an OPTIONS request.
     *
     * @return boolean whether this is a OPTIONS request.
     */
    public function isOptions()
    {
        return $this->getMethod() === 'OPTIONS';
    }

    /**
     * Returns whether this is a HEAD request.
     *
     * @return boolean whether this is a HEAD request.
     */
    public function isHead()
    {
        return $this->getMethod() === 'HEAD';
    }

    /**
     * Returns whether this is a POST request.
     *
     * @return boolean whether this is a POST request.
     */
    public function isPost()
    {
        return $this->getMethod() === 'POST';
    }

    /**
     * Returns whether this is a DELETE request.
     *
     * @return boolean whether this is a DELETE request.
     */
    public function isDelete()
    {
        return $this->getMethod() === 'DELETE';
    }

    /**
     * Returns whether this is a PUT request.
     *
     * @return boolean whether this is a PUT request.
     */
    public function isPut()
    {
        return $this->getMethod() === 'PUT';
    }

    /**
     * Returns whether this is a PATCH request.
     *
     * @return boolean whether this is a PATCH request.
     */
    public function isPatch()
    {
        return $this->getMethod() === 'PATCH';
    }

    private $_isAjax;

    /**
     * Returns whether this is an AJAX (XMLHttpRequest) request.
     *
     * @return boolean whether this is an AJAX (XMLHttpRequest) request.
     */
    public function isAjax()
    {
        if (isset($this->_isAjax)) {
            return $this->_isAjax;
        }
        return $this->_isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest';
    }

    /**
     * Sets a AJAX.
     * @param boolean $is
     * @return $this
     */
    public function setIsAjax($is)
    {
        $this->_isAjax = $is;
        return $this;
    }

    private $_isPjax;

    /**
     * Returns whether this is a PJAX request.
     *
     * @return boolean whether this is a PJAX request
     */
    public function isPjax()
    {
        if (isset($this->_isPjax)) {
            return $this->_isPjax;
        }
        return $this->_isPjax = $this->isAjax() && !empty($_SERVER['HTTP_X_PJAX']);
    }

    /**
     * Sets a Pjax.
     * @param boolean $is
     * @return $this
     */
    public function setIsPjax($is)
    {
        $this->_isPjax = $is;
        return $this;
    }

    private $_isFlash;

    /**
     * Returns whether this is an Adobe Flash or Flex request.
     *
     * @return boolean whether this is an Adobe Flash or Adobe Flex request.
     */
    public function isFlash()
    {
        if (isset($this->_isFlash)) {
            return $this->_isFlash;
        }

        return $this->_isFlash = isset($_SERVER['HTTP_USER_AGENT']) &&
            (stripos($_SERVER['HTTP_USER_AGENT'], 'Shockwave') !== false || stripos($_SERVER['HTTP_USER_AGENT'], 'Flash') !== false);
    }

    /**
     * Sets a Flash.
     * @param boolean $is
     * @return $this
     */
    public function setIsFlash($is)
    {
        $this->_isFlash = $is;
        return $this;
    }

    private $_isCORS;

    /**
     * Returns whether this is a CORS request.
     *
     * @return boolean whether this is a CORS request
     */
    public function isCORS()
    {
        if (isset($this->_isCORS)) {
            return $this->_isCORS;
        }
        return $this->_isCORS = !empty($_SERVER['HTTP_ORIGIN']);
    }

    /**
     * Sets a CORS.
     * @param boolean $is
     * @return $this
     */
    public function setIsCORS($is)
    {
        $this->_isCORS = $is;
        return $this;
    }

    public function __call($name, $arguments)
    {
        if (method_exists($this, "{$name}Internal")) {
            return call_user_func_array([$this, "{$name}Internal"], $arguments);
        }

        return $this->parentCall($name, $arguments);
    }

    public static function __callStatic($name, $arguments)
    {
        return call_user_func_array([Instance::ensure(static::className()), $name], $arguments);
    }

    protected function rawGetInternal($name = null, $default = null)
    {
        if (!isset($name)) {
            return $this->getQueryParams();
        }
        return $this->getQueryParam($name, $default);
    }

    protected function rawPostInternal($name = null, $default = null)
    {
        $params = $this->getBodyParams();

        if (!isset($name)) {
            return $params;
        }
        return ArrayHelper::getValue($params, $name, $default);
    }

    /**
     * Sanitize GET request-value.
     * @param string $name name of request-value.
     * @param mixed $default
     * @param Sanitize $sanitize
     * @return mixed
     */
    protected function getInternal($name = null, $default = null, Sanitize $sanitize = null)
    {
        return $this->sanitizeValue($this->rawGetInternal($name, $default), $sanitize);
    }

    /**
     * Sanitize POST request-value.
     * @param string $name name of request-value.
     * @param mixed $default
     * @param Sanitize $sanitize
     * @return mixed
     */
    protected function postInternal($name = null, $default = null, Sanitize $sanitize = null)
    {
        return $this->sanitizeValue($this->rawPostInternal($name, $default), $sanitize);
    }

    /**
     * Sanitize request-value.
     *
     * @param mixed $input
     * @param Sanitize $sanitize
     * @return null
     */
    protected function sanitizeValue($input, Sanitize $sanitize = null)
    {
        if (!isset($sanitize)) {
            $sanitize = $this->sanitize ?: Sanitize::removeTags()->trim()->toType();
        }

        if (is_array($input)) {
            $rawRule = $sanitize->getRawRules();
            $rawRule = current($rawRule);
            if ($rawRule instanceof Attributes) {
                return $sanitize->sanitize($input);
            }
            return Sanitize::attributes($sanitize)->sanitize($input);
        }

        return $sanitize->sanitize($input);
    }
}