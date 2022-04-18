<?php

class ReportNoDataException extends RuntimeException
{
    /**
     * Title of the report
     * @var string
     */
    private $title;

    public function __construct($title, $message = "")
    {
        parent::__construct($message);
        $this->title = $title;
    }

    public function getTitle() {
        return $this->title;
    }

    public function getMessageAsHTML() {
        return str_replace("\n", '<br/>', $this->message);
    }
}
