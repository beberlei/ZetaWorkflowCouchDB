<?php

class CouchWorkflow_InvalidWorkflowException extends Exception
{
    static public function missingIdAndRevision()
    {
        return new self("Pased document does not contain an id and revision and cannot be a CouchDB document.");
    }

    static public function notExecutionDocument($id)
    {
        return new self("Document '" . $id . "' does not contain a Zeta Workflow Execution.");
    }

    static public function invalidDocumentType($id)
    {
        return new self("Document '" . $id . "' does not contain a Zeta Workflow.");
    }
}