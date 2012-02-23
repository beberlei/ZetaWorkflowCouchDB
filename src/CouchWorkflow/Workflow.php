<?php

class CouchWorkflow_Workflow extends ezcWorkflow
{
    public function __set( $propertyName, $val )
    {
        switch ( $propertyName )
        {
            case 'definitionStorage':
                if ( !( $val instanceof ezcWorkflowDefinitionStorage ) )
                {
                    throw new ezcBaseValueException( $propertyName, $val, 'ezcWorkflowDefinitionStorage' );
                }

                $this->properties['definitionStorage'] = $val;

                return;

            case 'id':
                if ( !( is_scalar( $val ) ) )
                {
                    throw new ezcBaseValueException( $propertyName, $val, 'scalar' );
                }

                $this->properties['id'] = $val;

                return;

            case 'name':
                if ( !( is_string( $val ) ) )
                {
                    throw new ezcBaseValueException( $propertyName, $val, 'string' );
                }

                $this->properties['name'] = $val;

                return;

            case 'startNode':
            case 'endNode':
            case 'finallyNode':
            case 'nodes':
                throw new ezcBasePropertyPermissionException(
                  $propertyName, ezcBasePropertyPermissionException::READ
                );

            case 'version':
                if ( !( is_scalar( $val ) ) )
                {
                    throw new ezcBaseValueException( $propertyName, $val, 'scalar' );
                }

                $this->properties['version'] = $val;

                return;
        }

        throw new ezcBasePropertyNotFoundException( $propertyName );
    }
}