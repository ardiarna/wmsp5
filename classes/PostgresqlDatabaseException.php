<?php

class PostgresqlDatabaseException extends RuntimeException {
	const GENERAL_ERROR = 3;
	const STATE_RAISE_EXCEPTION = 'P0001';

	protected $query;

	protected $message;
	protected $originalMessage;
	protected $sqlState;
	protected $file;
	protected $line;

	public function __construct($message = "DatabaseException", $query = "", $code = self::GENERAL_ERROR, $originalMessage = "", $previous = null) {
		$this->query = $query;
		$this->originalMessage = $originalMessage;
		$this->sqlState = $code;

		parent::__construct($message, self::GENERAL_ERROR);
	}

    /**
     * Check if the exception is resulted from RAISE EXCEPTION statement.
     * @return bool
     */
	public function isRaisedManually() {
	    return $this->sqlState === PostgresqlDatabaseException::STATE_RAISE_EXCEPTION;
    }

    /**
     * Get last executed query.
     * @return string
     */
	public function getQuery() {
	    return $this->query;
    }

    /**
     * Get original message related to the error.
     * @return string
     */
	public function getOriginalMessage() {
		return $this->originalMessage;
	}

	public function __toString() {
		$msg = $this->message . ' last query: ' . $this->query;
		return $msg;
	}
}
