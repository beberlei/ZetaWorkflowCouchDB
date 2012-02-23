<?php

class CouchWorkflow_CouchHttpException extends Exception
{
    static public function factory($code, $method, $path)
    {
        switch ($code) {
            case 400:
                return new self("Bad Request made against " .$method . " " . $path, $code);
            case 404:
                return new self("View was not found " . $path, $code);
            case 405:
                return new self("Resource not allowed at " . $method . " " . $path, $code);
            case 409:
                return new self("Conflict detected for " . $path, $code);
            case 412:
                return new self("Precondition failed for " . $method . " " . $path, $code);
            case 500:
                return new self("Malformed request/json data for " . $method . " " . $path, $code);
            default:
                return new self("Unknown HTTP Error in communication with CouchDB at " . $method . " " . $path, $code);
        }
    }
}