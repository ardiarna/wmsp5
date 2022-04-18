<?php

if (!function_exists('http_response_code')) {
    function http_response_code($code = NULL)
    {
        $protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
        if ($code !== NULL) {
            switch ($code) {
                case 100:
                    $text = 'Continue';
                    break;
                case 101:
                    $text = 'Switching Protocols';
                    break;
                case 200:
                    $text = 'OK';
                    break;
                case 201:
                    $text = 'Created';
                    break;
                case 202:
                    $text = 'Accepted';
                    break;
                case 203:
                    $text = 'Non-Authoritative Information';
                    break;
                case 204:
                    $text = 'No Content';
                    break;
                case 205:
                    $text = 'Reset Content';
                    break;
                case 206:
                    $text = 'Partial Content';
                    break;
                case 300:
                    $text = 'Multiple Choices';
                    break;
                case 301:
                    $text = 'Moved Permanently';
                    break;
                case 302:
                    $text = 'Moved Temporarily';
                    break;
                case 303:
                    $text = 'See Other';
                    break;
                case 304:
                    $text = 'Not Modified';
                    break;
                case 305:
                    $text = 'Use Proxy';
                    break;
                case 400:
                    $text = 'Bad Request';
                    break;
                case 401:
                    $text = 'Unauthorized';
                    break;
                case 402:
                    $text = 'Payment Required';
                    break;
                case 403:
                    $text = 'Forbidden';
                    break;
                case 404:
                    $text = 'Not Found';
                    break;
                case 405:
                    $text = 'Method Not Allowed';
                    break;
                case 406:
                    $text = 'Not Acceptable';
                    break;
                case 407:
                    $text = 'Proxy Authentication Required';
                    break;
                case 408:
                    $text = 'Request Time-out';
                    break;
                case 409:
                    $text = 'Conflict';
                    break;
                case 410:
                    $text = 'Gone';
                    break;
                case 411:
                    $text = 'Length Required';
                    break;
                case 412:
                    $text = 'Precondition Failed';
                    break;
                case 413:
                    $text = 'Request Entity Too Large';
                    break;
                case 414:
                    $text = 'Request-URI Too Large';
                    break;
                case 415:
                    $text = 'Unsupported Media Type';
                    break;
                case 422:
                    $text = 'Unprocessable Entity';
                    break;
                case 500:
                    $text = 'Internal Server Error';
                    break;
                case 501:
                    $text = 'Not Implemented';
                    break;
                case 502:
                    $text = 'Bad Gateway';
                    break;
                case 503:
                    $text = 'Service Unavailable';
                    break;
                case 504:
                    $text = 'Gateway Time-out';
                    break;
                case 505:
                    $text = 'HTTP Version not supported';
                    break;
                default:
                    header($protocol . ' 500 Internal Server Error');
                    exit('Unknown http status code "' . htmlentities($code) . '"');
                    break;
            }
            header($protocol . ' ' . $code . ' ' . $text);
            $GLOBALS['http_response_code'] = $code;
        } else {
            $code = (isset($GLOBALS['http_response_code']) ? $GLOBALS['http_response_code'] : 200);
        }

        return $code;
    }
}

class HttpUtils
{
    const MIME_JSON = 'application/json';
    const MIME_XML = 'application/xml';

    const STATUS_OK = 1; // default status
    const STATUS_SERVER_ERROR = 2; // for server errors
    const STATUS_AUTH_INVALID = 3; // for authentication errors
    const STATUS_NOT_FOUND = 4; // for data not found in DB
    const STATUS_BAD_REQUEST = -1; // for syntactic errors (e.g. invalid request params)
    const STATUS_UNPROCESSABLE_STATE = 6; // for semantic errors (e.g. invalid precondition state)

    const HTTP_RESPONSE_OK = 200;
    const HTTP_RESPONSE_CREATED = 201;

    const HTTP_RESPONSE_BAD_REQUEST = 400;
    const HTTP_RESPONSE_UNAUTHORIZED = 401;
    const HTTP_RESPONSE_FORBIDDEN = 403;
    const HTTP_RESPONSE_NOT_FOUND = 404;
    const HTTP_RESPONSE_UNPROCESSABLE_ENTITY = 422;

    const HTTP_RESPONSE_SERVER_ERROR = 500;

    const MODE_JSON = 'json';
    const MODE_XML = 'xml';

    static $VALID_MODES = array(self::MODE_JSON, self::MODE_XML);

    /**
     * Checks if the resulting response mode is known to the class.
     * @param string $mode
     * @return bool
     */
    public static function isValidMode($mode)
    {
        return in_array($mode, self::$VALID_MODES, true);
    }

    /**
     * Gets all HTTP request headers as an associative array.
     * @link https://stackoverflow.com/a/541463/2935556 original source
     * @return array
     */
    public static function getAllRequestHeaders()
    {
        $headers = array();
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) !== 'HTTP_') {
                continue;
            }

            $header = str_replace(' ', '-', ucwords(str_replace('_', ' ', strtolower(substr($key, 5)))));
            $headers[$header] = $value;
        }

        return $headers;
    }

    /**
     * Get raw header value from the request
     * @param string $header header name to get
     * @param string $default the default value, if it does not exist.
     * @return string
     */
    public static function getRawHeaderValue($header, $default = '')
    {
        $p_header = str_replace('-', '_', strtoupper($header));
        $val = $default;

        if ($p_header === "CONTENT_TYPE" || $p_header === "CONTENT_LENGTH") {
            $sKey_header = $p_header;
            if (isset($_SERVER[$sKey_header])) {
                $val = $_SERVER[$sKey_header];
            } else if (isset($_SERVER["HTTP_$sKey_header"])) {
                // try one more time with HTTP_
                $val = $_SERVER["HTTP_$sKey_header"];
            }
        } else {
            $sKey_header = "HTTP_${p_header}";
            if (isset($_SERVER[$sKey_header])) {
                $val = $_SERVER[$sKey_header];
            }
        }

        return $val;
    }

    /**
     * Checks if the request sent is a JSON.
     * @return bool
     */
    public static function sendsJson()
    {
        $contentType = self::getRawHeaderValue('Content-Type');

        // since the client may send the charset in Content-Type, get the substring before first ';'
        $delimiterPosition = strpos($contentType, ';');
        if ($delimiterPosition) {
            $contentType = substr($contentType, 0, $delimiterPosition);
        }

        return $contentType === self::MIME_JSON;
    }

    /**
     * Get the values from request body.
     * @param array $validKeys keys to be fetched from the request body, with the following descending order of importance:
     *  - JSON
     *  - POST
     *  - GET
     * @param mixed $defaultValue the default value to be supplied to all parameters, if it does not exist.
     * @return array an array containing
     */
    public static function getRequestValues(array $validKeys = array(), $defaultValue = '')
    {
        $request = array();
        if (self::sendsJson()) {
            // try to parse
            $requestContent = self::getJsonRequest();
            if (is_null($requestContent)) {
                return $request;
            }
            $request = self::getFilteredTuple($requestContent, $validKeys, $defaultValue);
        } else {
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $postRequests = array();
                if (isset($_POST)) {
                    $postRequests = self::getFilteredTuple($_POST, $validKeys, $defaultValue);
                }
                foreach ($postRequests as $key => $value) {
                    $request[$key] = $value;
                }
            }

            $getRequests = array();
            if (isset($_GET)) {
                $getRequests = self::getFilteredTuple($_GET, $validKeys, $defaultValue);
            }
            foreach ($getRequests as $key => $getValue) {
                if (empty($request[$key])) {
                    $request[$key] = $getValue;
                }
            }
        }

        return $request;
    }

    /**
     * Get the JSON as an associative array from request body.
     * Returns null if JSON is not valid.
     * @return array|null
     */
    public static function getJsonRequest()
    {
        $requestBody = file_get_contents('php://input');
        if (self::isValidJson($requestBody)) {
            return json_decode($requestBody, true);
        } else {
            return null;
        }
    }

    private static function getFilteredTuple(array $source, array $validKeys, $defaultValue)
    {
        $filteredTuple = array();

        if (count($validKeys) === 0) {
            $filteredTuple = $source;
        } else {
            foreach ($validKeys as $validKey) {
                $filteredTuple[$validKey] = isset($source[$validKey]) ? $source[$validKey] : $defaultValue;
            }
        }

        return $filteredTuple;
    }

    private static function isValidJson($str)
    {
        $isNotEmpty = strlen($str) > 0;
        $obj = json_decode($str);

        // since the server is using PHP 5.2,
        // json_last_error() is not available.
        // therefore, need to check also for 'null' as body.
        $isBodyNull = strcmp($str, 'null') === 0;

        return $isNotEmpty && (!is_null($obj) || $isBodyNull);
    }

    /**
     * Sends a JSON response with the following properties:
     *  - (int) code: application statusCode
     *  - (string) msg: related message to the response
     *  - (string|null) username: user that requests the response
     *  - (*) data: additional content to be sent along with the response, e.g. values fetched from the DB.
     *
     * This method assumes that no HTTP output has been written.
     * @param array|stdClass $content additional content to be sent as 'data' property
     * @param string $msg message related to the response
     * @param null|string $userid user that requests the response
     * @param int $statusCode application statusCode
     * @param int $httpCode HTTP status code
     */
    public static function sendJsonResponse($content, $msg = '', $userid = null, $statusCode = self::STATUS_OK, $httpCode = self::HTTP_RESPONSE_OK)
    {
        header('Content-Type: ' . self::MIME_JSON);
        http_response_code($httpCode);
        $payload = array(
            'code' => $statusCode,
            'msg' => $msg,
            'userid' => $userid,
            'data' => $content
        );

        echo json_encode($payload);
    }

    /**
     * Sends an XML response.
     * This method assumes that no HTTP output has been written.
     *
     * @param array|stdClass $content content to be transformed to XML
     * @param string $idKey array key that will be mapped as an XML attribute, rather than as a XML child.
     * @param string $rootId user that requests the response
     * @param int $httpStatusCode HTTP status code
     */
    public static function sendXmlResponse(array $content, $idKey = 'id', $rootId = 'rows', $httpStatusCode = self::HTTP_RESPONSE_OK)
    {
        header('Content-Type: ' . self::MIME_XML);
        http_response_code($httpStatusCode);

        $rootElement = "<$rootId></$rootId>";
        $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?>' . $rootElement);
        ArrayUtils::arrayToXml($content, $xml, $idKey);

        echo $xml->asXML();
    }

    /**
     * Sends an error.
     * This method assumes that no HTTP output has been written.
     *
     * @param string $mode one of the valid modes
     * @param string $message related error message
     * @param array $additionalParams additional response.
     * @param int $httpCode HTTP code
     */
    public static function sendError($mode, $message, $additionalParams = array(), $httpCode = self::HTTP_RESPONSE_SERVER_ERROR)
    {
        switch ($mode) {
            case self::MODE_XML:
                self::sendXmlError($message, $httpCode, $additionalParams);
                exit;
            case self::MODE_JSON:
                $statusCode = null;
                if ($httpCode === self::HTTP_RESPONSE_UNAUTHORIZED) {
                    $statusCode = self::STATUS_AUTH_INVALID;
                } else if ($httpCode === self::HTTP_RESPONSE_BAD_REQUEST) {
                    $statusCode = self::STATUS_BAD_REQUEST;
                } else {
                    $statusCode = self::STATUS_SERVER_ERROR;
                }
                $userid = null;
                if (is_array($additionalParams) && isset($additionalParams['userid'])) {
                    $userid = $additionalParams['userid'];
                    unset($additionalParams['userid']);
                }
                self::sendJsonError($message, $additionalParams, $statusCode, $httpCode, $userid);
                exit;
            default:
                throw new InvalidArgumentException("Unknown mode [$mode]!");
        }
    }

    private static function sendJsonError($message, array $errors = array(), $statusCode = self::STATUS_SERVER_ERROR, $httpCode = self::HTTP_RESPONSE_SERVER_ERROR, $userid = null)
    {
        self::sendJsonResponse($errors, $message, $userid, $statusCode, $httpCode);
    }

    private static function sendXmlError($message, $httpCode = self::HTTP_RESPONSE_SERVER_ERROR, $additionalErrors = null)
    {
        $errors = array('message' => $message);
        if (is_array($additionalErrors)) {
            $errors = array_merge($errors, $additionalErrors);
        }
        self::sendXmlResponse($errors, null, 'error', $httpCode);
    }

    /**
     * Gets the origin URL (protocol + host) of the current request.
     *
     * @param array $s server array ($_SERVER)
     * @param bool $use_forwarded_host
     * @return string origin URL of the request
     */
    public static function originUrl( $s, $use_forwarded_host = false )
    {
        $ssl      = ( ! empty( $s['HTTPS'] ) && $s['HTTPS'] == 'on' );
        $sp       = strtolower( $s['SERVER_PROTOCOL'] );
        $protocol = substr( $sp, 0, strpos( $sp, '/' ) ) . ( ( $ssl ) ? 's' : '' );
        $port     = $s['SERVER_PORT'];
        $port     = ( ( ! $ssl && $port=='80' ) || ( $ssl && $port=='443' ) ) ? '' : ':'.$port;
        $host     = ( $use_forwarded_host && isset( $s['HTTP_X_FORWARDED_HOST'] ) ) ? $s['HTTP_X_FORWARDED_HOST'] : ( isset( $s['HTTP_HOST'] ) ? $s['HTTP_HOST'] : null );
        $host     = isset( $host ) ? $host : $s['SERVER_NAME'] . $port;
        return $protocol . '://' . $host;
    }

    /**
     * Gets the full URL of the current request.
     *
     * @param array $s server array ($_SERVER)
     * @param bool $use_forwarded_host
     * @return string full URL of the current request.
     */
    public static function fullUrl( $s, $use_forwarded_host = false )
    {
        return self::originUrl( $s, $use_forwarded_host ) . $s['REQUEST_URI'];
    }
}
