<?php

// statically set BASE_PATH
// update this when refactoring this class
use Utils\Env;

define('BASE_PATH', substr(dirname(__DIR__), strlen($_SERVER['DOCUMENT_ROOT'])));

class SessionUtils
{
    /**
     * TODO refactor the params, make them set from the ENV/config file.
     */
    public static function sessionStart($limit = 0, $name = 'wms', $path = BASE_PATH, $domain = null, $secure = null)
    {
        // set the cookie name before starting
        session_name($name . '_session');

        // Set the domain to default to the current domain.
        $domain = isset($domain) ? $domain : isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : null;

        // Set the default secure value to whether the site is being accessed with SSL
        $https = isset($secure) ? $secure : isset($_SERVER['HTTPS']);

        $path = self::normalizePath($path);
        if ($path[-1] !== '/')
        {
            $path .= '/';
        }
        if ($path[0] !== '/')
        {
            $path = '/' . $path;
        }

        // Set the cookie settings and start the session
        session_set_cookie_params($limit, $path, $domain, $secure, true);
        session_start();

        // Make sure the session hasn't expired, and destroy it if it has
        if (self::validateSession()) {
            // Check to see if the session is new or a hijacking attempt
            if (!self::preventHijacking()) {
                // Reset session data and regenerate id
                $_SESSION = array();
                $_SESSION['IPaddress'] = self::getClientIpAddress();
                $_SESSION['userAgent'] = $_SERVER['HTTP_USER_AGENT'];
                self::regenerateSession();
            } elseif (rand(1, 100) <= 5) {  // Give a 5% chance of the session id changing on any request
                self::regenerateSession();
            }
        } else {
            $_SESSION = array();
            session_destroy();
            session_start();
        }
    }

    protected static function getClientIpAddress()
    {
        foreach (array('HTTP_CLIENT_IP',
                     'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED',
                     'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR',
                     'HTTP_FORWARDED', 'REMOTE_ADDR') as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    $ip = trim($ip); // just to be safe

                    return $ip;
                }
            }
        }
        return null;
    }

    protected static function preventHijacking()
    {
        if (!isset($_SESSION['IPaddress']) || !isset($_SESSION['userAgent']))
            return false;

        if ($_SESSION['IPaddress'] !== self::getClientIpAddress())
            return false;

        if ($_SESSION['userAgent'] !== $_SERVER['HTTP_USER_AGENT'])
            return false;

        return true;
    }

    protected static function validateSession()
    {
        if (isset($_SESSION['OBSOLETE']) && !isset($_SESSION['EXPIRES']))
            return false;

        if (isset($_SESSION['EXPIRES']) && $_SESSION['EXPIRES'] < time())
            return false;

        return true;
    }

    public static function regenerateSession()
    {
        // If this session is obsolete it means there already is a new id
        if (isset($_SESSION['OBSOLETE']) && $_SESSION['OBSOLETE'] === true)
            return;

        // Set current session to expire in 10 seconds
        $_SESSION['OBSOLETE'] = true;
        $_SESSION['EXPIRES'] = time() + 10;

        // Create new session without destroying the old one
        $sessionCookieParams = session_get_cookie_params();
        session_regenerate_id(false);

        // Grab current session ID and close both sessions to allow other scripts to use them
        $newSession = session_id();
        session_write_close();

        // Set session ID to the new one, and start it back up again
        session_id($newSession);
        session_set_cookie_params(
            $sessionCookieParams['lifetime'],
            $sessionCookieParams['path'],
            $sessionCookieParams['domain'],
            $sessionCookieParams['secure'],
            $sessionCookieParams['httponly']
        );
        session_start();

        // Now we unset the obsolete and expiration values for the session we want to keep
        unset($_SESSION['OBSOLETE']);
        unset($_SESSION['EXPIRES']);
    }

    public static function isAuthenticated()
    {
        return isset($_SESSION['user']) && $_SESSION['user'] !== null;
    }

    /**
     * @return stdClass|null
     */
    public static function getUser()
    {
        return isset($_SESSION['user']) ? $_SESSION['user'] : null;
    }

    private static function normalizePath($path)
    {
        $path = str_replace( '\\', '/', $path );
        $path = preg_replace( '|(?<=.)/+|', '/', $path );
        if ( ':' === substr( $path, 1, 1 ) ) {
            $path = ucfirst( $path );
        }
        return $path;
    }
}
