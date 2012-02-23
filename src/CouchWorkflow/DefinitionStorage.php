<?php

class CouchWorkflow_DefinitionStorage implements ezcWorkflowDefinitionStorage
{
    /**
     * @var CouchWorkflow_CouchClient
     */
    private $client;

    /**
     * @var array
     */
    private $nodeIds = array();

    /**
     * @var int
     */
    private $nodeIdCounter = 0;

    public function __construct(CouchWorkflow_CouchClient $client)
    {
        $this->client = $client;
    }

    public function loadById($workflowId)
    {
        $body = $this->client->request('GET', "/" . $workflowId);

        return $this->loadWorkflowFromDocument($body);
    }

    /**
     * @param  string $workflowName
     * @param  string $workflowVersion
     * @return CouchWorkflow_Workflow
     */
    public function loadByName($workflowName, $workflowVersion = 0)
    {
        if ($workflowVersion == 0) {
            $params = array('key' => array($workflowName), 'descending' => true, 'limit' => 1);
        } else {
            $params = array('key' => array($workflowName, $workflowVersion));
        }
        $body = $this->client->request('GET', "/_design/workflow/_view/by-name-version", null, $params);

        if ($body['total_rows'] != 1) {
            throw new RuntimeException("Only exactly 1 result was expected, but " . $body['total_rows'] . " found!");
        }

        return $this->loadWorkflowFromDocument($body['rows'][0]['value']);
    }

    public function loadWorkflowFromDocument($doc)
    {        
        $nodes = array();
        $startNode = null;
        $defaultEndNode = null;
        $finallyNode = null;
        $connections = array();

        if (!isset($doc['_id']) || !isset($doc['_rev'])) {
            throw CouchWorkflow_InvalidWorkflowException::missingIdAndRevision();
        }

        if (!isset($doc['type']) || $doc['type'] != "zeta_workflow") {
            throw CouchWorkflow_InvalidWorkflowException::invalidDocumentType($doc['id']);
        }

        foreach ($doc['nodes'] AS $id => $data) {
            
            $configuration = $data['configuration'];

            if ($configuration === null)
            {
                $configuration = ezcWorkflowUtil::getDefaultConfiguration( $data['class'] );
            }

            // If there is a configuration xml node we have to deserialize it correctly
            if (isset($data['configurationXml'])) {
                $dom = new DOMDocument('1.0', 'UTF-8');
                $dom->loadXML($data['configurationXml']);

                foreach ($dom->documentElement->childNodes AS $childNode) {
                    /* @var $childNode DOMNode */
                    if ($childNode->nodeName == "conditionArray") {
                        $configuration['condition'] = array();
                        foreach ($childNode->childNodes AS $conditionChildNode) {
                            $key = $conditionChildNode->attributes->getNamedItem('key')->value;
                            $condition = ezcWorkflowDefinitionStorageXml::xmlToCondition($conditionChildNode->childNodes->item(0));
                            $configuration['condition'][$key] = $condition;
                        }
                    } else {
                        $condition = ezcWorkflowDefinitionStorageXml::xmlToCondition($childNode->childNodes->item(0));
                        $configuration[$childNode->nodeName] = $condition;
                    }
                }
            }

            $nodes[$id] = new $data['class']($configuration);

            if ($nodes[$id] instanceof ezcWorkflowNodeFinally && $finallyNode === null )
            {
                $finallyNode = $nodes[$id];
            }
            else if ($nodes[$id] instanceof ezcWorkflowNodeEnd && $defaultEndNode === null)
            {
                $defaultEndNode = $nodes[$id];
            }
            else if ($nodes[$id] instanceof ezcWorkflowNodeStart && $startNode === null)
            {
               $startNode = $nodes[$id];
            }

            foreach ($data['outgoingNodes'] AS $outgoingNodeId) {
                $connections[] = array('incoming_node_id' => $id, 'outgoing_node_id' => $outgoingNodeId);
            }
        }

        if ( !isset( $startNode ) || !isset( $defaultEndNode ) )
        {
            throw new ezcWorkflowDefinitionStorageException(
              'Could not load workflow definition.'
            );
        }

        foreach ( $connections as $connection )
        {
            $nodes[$connection['incoming_node_id']]->addOutNode(
              $nodes[$connection['outgoing_node_id']]
            );
        }

        $workflow = new CouchWorkflow_Workflow($doc['name'], $startNode, $defaultEndNode, $finallyNode);
        $workflow->definitionStorage = $this;
        $workflow->id = $doc['_id'];
        $workflow->version = $doc['_rev'];

        if (isset($doc['variableHandlers']) && is_array($doc['variableHandlers'])) {
            foreach ($doc['variableHandlers'] AS $varName => $handler) {
                $workflow->addVariableHandler($varName, $handler);
            }
        }

        // Verify the loaded workflow.
        $workflow->verify();

        return $workflow;
    }

    /**
     * Converting Object Hashes into incremented node-ids for document size optimizations.
     *
     * @param ezcWorkflowNode $node
     * @return int
     */
    private function getNodeId($node)
    {
        $hash = spl_object_hash($node);
        if (!isset($this->nodeIds[$hash])) {
            $this->nodeIds[$hash] = ++$this->nodeIdCounter;
        }
        return $this->nodeIds[$hash];
    }

    public function save(ezcWorkflow $workflow)
    {
        if ( !($workflow instanceof CouchWorkflow_Workflow)) {
            throw new InvalidArgumentException("Currently onlw CouchWorkflow_Workflow intances are supported!");
        }

        // Verify the workflow.
        $workflow->verify();

        $data = array();
        $data['version'] = microtime(true) - 1280354400; // make the number initially smaller!
        $data['name'] = $workflow->name;
        $data['type'] = 'zeta_workflow'; //@todo configurable
        $data['created'] = time();
        $data['nodes'] = array();

        $this->nodeIds = array();
        $this->nodeIdCounter = 0;
        foreach ($workflow->nodes AS $node) {
            $nodeId = $this->getNodeId($node);

            $outgoingNodeIds = array();
            foreach ($node->getOutNodes() AS $outNode) {
                $outgoingNodeIds[] = $this->getNodeId($outNode);
            }

            $configXml = new DOMDocument('1.0', 'UTF-8');
            $configXml->appendChild($configXml->createElement('config'));
            $config = $node->getConfiguration();
            if (is_array($config)) {
                foreach ($config AS $key => $var) {
                    if (is_object($var)) {
                        if ($var instanceof ezcWorkflowCondition) {
                            $keyElement = $configXml->createElement($key);
                            $configXml->documentElement->appendChild($keyElement);
                            $keyElement->appendChild(ezcWorkflowDefinitionStorageXml::conditionToXml($var, $configXml));

                            unset($config[$key]);
                        } else {
                            // ieeks objects :-)
                            $config[$key] = serialize($var);
                        }
                    } else if ($key == 'condition' && is_array($var)) {
                        // this is a special case for all the ezcWorkflowNodeBranch implementations. It has to be handled
                        // as special case for deerializing also.
                        $keyElement = $configXml->createElement('conditionArray');
                        $configXml->documentElement->appendChild($keyElement);
                        foreach ($var AS $_key => $condition) {
                            $conditionKeyElement = $configXml->createElement("outNode");
                            $conditionKeyElement->setAttribute('key', $_key);
                            $keyElement->appendChild($conditionKeyElement);
                            $conditionKeyElement->appendChild(ezcWorkflowDefinitionStorageXml::conditionToXml($condition, $configXml));
                        }
                        unset($config[$key]);
                    }
                }
            }

            $nodeData = array(
                'class' => get_class($node),
                'configuration' => $config,
                'outgoingNodes' => $outgoingNodeIds,
            );

            // save configuration xml if necessary!
            if ($configXml->documentElement->hasChildNodes()) {
                $nodeData['configurationXml'] = $configXml->saveXML();
            }

            $data['nodes'][$nodeId] = $nodeData;
        }

        foreach ($workflow->getVariableHandlers() as $variable => $class) {
            if (!isset($data['variableHandles'])) {
                $data['variableHandlers'] = array();
            }
            $data['variableHandlers'][$variable] = $class;
        }

        $response = $this->client->request('POST', '', $data);

        if (isset($response['ok']) && $response['ok'] == true) {
            $workflow->id = $response['id'];
            $workflow->version = $response['rev'];
        } else {
            // TODO: something
        }
        return $workflow;
    }
}
