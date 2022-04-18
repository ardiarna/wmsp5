<?php

namespace Utils;

use Dotenv\Dotenv;
use Monolog\Formatter\LineFormatter;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use RequestParamProcessor;

class FileLog
{
    /**
     * Path to log directory
     * @var string
     */
    private static $LOG_DIR;

    private static $_instances = array();

    /**
     * Get instance of Logger
     * If debug is set to true, will give DEBUG level logger.
     * Otherwise, log level will be set to NOTICE.
     *
     * @param string $channel channel to log the file to. Will be used as filename.
     * @return Logger
     * @throws \Exception
     */
    public static function getInstance($channel = 'app') {
        if (!isset(static::$_instances[$channel])) {
            // setup mail
            $logger = new Logger("file-$channel");

            $fileName = Env::get('LOG_DIR') . DIRECTORY_SEPARATOR . "${channel}.log";
            $formatter = new LineFormatter(null, null, false, true);
            $fileHandler = new StreamHandler($fileName, Env::isDebug() ? Logger::DEBUG : Logger::INFO);
            $fileHandler->setFormatter($formatter);

            $logger->pushHandler($fileHandler);
            if (Env::isDebug()) {
                $stdoutHandler = new StreamHandler('php://stdout');
                $stdoutHandler->setFormatter($formatter);
                $logger->pushHandler($stdoutHandler);
            }
            static::$_instances[$channel] = $logger;
        }

        return static::$_instances[$channel];
    }
}
