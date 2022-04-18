<?php

namespace Utils;

use Dotenv\Dotenv;

class Env
{
    /**
     * @var Dotenv
     */
    private static $_instance;

    private static $requiredParams = array(
        // for database
        'DB_HOST', 'DB_NAME', 'DB_USER',
        'PLANT_ID', 'LOG_DIR',
        'MAIL_HOST', 'MAIL_USER', 'MAIL_PASS',
        'PALLET_AGING_REPORT_RECIPIENTS_TO',
        'REPORT_WEEKLY_PALLET_DEAD_STOCK_AND_SLOW_RECIPIENTS_TO'
    );

    private static function init() {
        if (!isset(self::$_instance)) {
            $dotenv = new Dotenv(dirname(dirname(__DIR__)));
            $dotenv->load();
            $dotenv->required(self::$requiredParams);

            self::$_instance = $dotenv;

            // LOG_DIR: make path absolute, if relative
            if (!self::isAbsolutePath(self::get('LOG_DIR'))) {
                // NOTE: change this whenever this class is moved.
                $basepath = dirname(dirname(__DIR__));
                $_ENV['LOG_DIR'] = $basepath . DIRECTORY_SEPARATOR . $_ENV['LOG_DIR'];
            }
        }
    }

    /**
     * Get value from environment variable.
     * @param string $var variable to get
     * @param mixed $defaultValue default value to send, if variable does not exist.
     * @return mixed
     */
    public static function get($var, $defaultValue = null) {
        self::init();

        return @$_ENV[$var] ?: $defaultValue;
    }

    /**
     * Checks if an environment variable is set.
     * @param string|string[] $vars environment variable(s) to check.
     * @return bool
     */
    public static function has($vars) {
        self::init();

        if (is_array($vars)) {
            foreach ($vars as $var) {
                if (isset($_ENV[$var])) {
                    return true;
                }
            }
            return false;
        }
        return isset($_ENV[$vars]);
    }

    /**
     * Checks if the app is in debug mode.
     * @return bool
     */
    public static function isDebug() {
        return \RequestParamProcessor::getBoolean(self::get('DEBUG', false));
    }

    /**
     * Checks if a path is absolute.
     * @link https://stackoverflow.com/a/38022806
     * @param $path
     * @return bool
     */
    private static function isAbsolutePath($path) {
        if (!is_string($path)) {
            $mess = sprintf('String expected but was given %s', gettype($path));
            throw new \InvalidArgumentException($mess);
        }
        if (!ctype_print($path)) {
            $mess = 'Path can NOT have non-printable characters or be empty';
            throw new \DomainException($mess);
        }
        // Optional wrapper(s).
        $regExp = '%^(?<wrappers>(?:[[:print:]]{2,}://)*)';
        // Optional root prefix.
        $regExp .= '(?<root>(?:[[:alpha:]]:/|/)?)';
        // Actual path.
        $regExp .= '(?<path>(?:[[:print:]]*))$%';
        $parts = array();
        if (!preg_match($regExp, $path, $parts)) {
            $mess = sprintf('Path is NOT valid, was given %s', $path);
            throw new \DomainException($mess);
        }
        if ('' !== $parts['root']) {
            return true;
        }
        return false;
    }
}
