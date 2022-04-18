<?php

use Utils\Env;

class PostgresqlDatabase
{
    const PGSQL_TRUE = 't';
    const PGSQL_FALSE = 'f';

    const PGSQL_DATETIME_LOCAL_FORMAT = 'Y-m-d H:i:s';
    const PGSQL_DATE_FORMAT = 'Y-m-d';

    const PGSQL_TIMESTAMP_FORMAT = 'Y-m-d H:i:s.u';
    /**
     * Database instance
     * @var PostgresqlDatabase
     */
    private static $instance;

    /**
     * Connection to db
     * @var resource
     */
    private $connection;

    private $isClosed = false;

    private $host;
    private $port;
    private $username;
    private $password;
    private $databaseName;

    public static function getInstance()
    {
        $host = Env::get('DB_HOST');
        $port = Env::get('DB_PORT', 5432);
        $databaseName = Env::get('DB_NAME');
        $username = Env::get('DB_USER');
        $password = Env::get('DB_PASS', '');

        if (!isset(self::$instance)) {
            self::$instance = new PostgresqlDatabase($host, $port, $username, $password, $databaseName);
        }

        return self::$instance;
    }

    public static function toBoolean($boolString) {
        return $boolString === static::PGSQL_TRUE;
    }

    /**
     * Converts an array to a Postgresql-compatible definition of array.
     * source: https://stackoverflow.com/a/5632171
     * @param array $arr array to be converted.
     * @return string
     */
    public static function toArray(array $arr)
    {
        $result = array();
        foreach ($arr as $t) {
            if (is_array($t)) {
                $result[] = self::toArray($t);
            } else {
                $t = str_replace('"', '\\"', $t); // escape double quote
                if (!is_numeric($t)) // quote only non-numeric values
                    $t = '"' . $t . '"';
                $result[] = $t;
            }
        }
        return '{' . implode(",", $result) . '}'; // format
    }

    private function __construct($host, $port, $username, $password, $databaseName)
    {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->databaseName = $databaseName;

        // initiate connection
        $this->checkConnection('');
    }

    public function __destruct()
    {
        if (!is_null($this->connection)) {
            @pg_close($this->connection);
        }
    }

    public function close()
    {
        if (!$this->isClosed) {
            if (!is_null($this->connection)) {
                pg_close($this->connection);
                $this->connection = null;
            }
            $this->isClosed = true;
        } else {
            throw new PostgresqlDatabaseException('Connection has been closed!');
        }
    }

    /**
     * Execute a parameterized query
     * @param string $query the query to be executed
     * @param array $params parameter(s) to be bound to the query
     * @return null|resource
     */
    public function parameterizedQuery($query, array $params)
    {
        $processedParams = array();
        foreach ($params as $param) {
            if (is_array($param)) {
                $processedParams[] = self::toArray($param);
            } elseif (is_bool($param)) {
                $processedParams[] = $param ? self::PGSQL_TRUE : self::PGSQL_FALSE;
            } else {
                $processedParams[] = "$param";
            }
        }

        $this->checkConnection($query);
        $sendSuccess = pg_send_query_params($this->connection, $query, $processedParams);
        $result = $this->getLastQueryResult($sendSuccess, $query);

        return $result;
    }

    public function rawQuery($query)
    {
        $this->checkConnection($query);
        $sendSuccess = pg_send_query($this->connection, $query);
        $result = $this->getLastQueryResult($sendSuccess, $query);

        return $result;
    }

    public function reconnect()
    {
        if (!isset($this->connection)) {
            $host = $this->host;
            $port = $this->port;
            $databaseName = $this->databaseName;
            $username = $this->username;
            $password = $this->password;

            $this->connection = @pg_connect("host='$host' port='$port' dbname='$databaseName' user='$username' password='$password'");

            if (!$this->connection || !is_resource($this->connection)) { // connection failed
                throw new PostgresqlDatabaseException('Cannot connect to database!', "");
            }
            $this->isClosed = false;
        }
    }

    private function checkConnection($query)
    {
        if ($this->isClosed) {
            throw new PostgresqlDatabaseException('Connection has been closed!', "");
        }
        if (!isset($this->connection)) {
            $host = $this->host;
            $port = $this->port;
            $databaseName = $this->databaseName;
            $username = $this->username;
            $password = $this->password;

            $this->connection = pg_connect("host=$host port=$port dbname=$databaseName user=$username password=$password");

            if (!$this->connection || !is_resource($this->connection)) { // connection failed
                throw new PostgresqlDatabaseException('Cannot connect to database!', "");
            }
        }

        if (pg_connection_busy($this->connection)) {
            throw new PostgresqlDatabaseException('Connection is busy.', $query);
        }
    }

    private function getLastQueryResult($resultSent, $query)
    {
        $result = null;
        if ($resultSent) {
            $result = pg_get_result($this->connection);
        } else {
            throw new PostgresqlDatabaseException('Cannot send result.', $query);
        }

        if (!is_resource($result)) {
            throw new PostgresqlDatabaseException('No result available.', $query);
        }

        // now check the SQL state
        $sqlState = pg_result_error_field($result, PGSQL_DIAG_SQLSTATE);
        if ($sqlState != 0) {
            // construct the error message
            $severity = pg_result_error_field($result, PGSQL_DIAG_SEVERITY);
            $errorPrimary = pg_result_error_field($result, PGSQL_DIAG_MESSAGE_PRIMARY);
            $errorDetails = pg_result_error_field($result, PGSQL_DIAG_MESSAGE_DETAIL);

            $errorMessage = "Caught SQL error: [$severity] $errorPrimary\n$errorDetails";
            throw new PostgresqlDatabaseException($errorMessage, $query, $sqlState, $errorDetails);
        }

        return $result;
    }
}
