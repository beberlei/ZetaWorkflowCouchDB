<?php

require_once "/home/benny/code/php/wsnetbeans/ezc/trunk/Base/src/base.php";

spl_autoload_register(array("ezcBase", "autoload"));

class SheldonsFriendAlgo_EvaluateActivity implements ezcWorkflowServiceObject
{
    public function __toString()
    {
        return "evaluateActivity";
    }

    public function execute(ezcWorkflowExecution $execution)
    {
        $sheldonLikes  = (rand(0, 100) <= 10);

        $execution->setVariable('sheldonLikesActivity', $sheldonLikes);

        // also remember all the activities!
        if ($execution->hasVariable('rememberActivities')) {
            $activities = $execution->getVariable('rememberActivities');
        } else {
            $activities = array();
        }
        $activities[] = $execution->getVariable('recreationalActivity');
        $execution->setVariable('rememberActivities', $activities);

        return true;
    }
}

class SheldonsFriendAlgo_LeastObjectionableActivity implements ezcWorkflowServiceObject
{
    public function __toString()
    {
        return 'leastObjectionableActivity';
    }
    
    public function execute(ezcWorkflowExecution $execution)
    {
        $activities = $execution->getVariable('rememberActivities');
        $activity = array_rand($activities);

        echo "Perform least objectionable activity: " . $activity . "\n";
    }
}

// action nodes use "some sort" of output mechanism to ask the user for input
// and users reply with some sort of input mechanism.
//
// each workflow has to be wrapped by a workflow execution displayer, i.e. for each waiting for variable an input mechanism has to be defined.
//

class SheldonsFriendAlgo_StatusMessage implements ezcWorkflowServiceObject
{
    private $message;

    public function __construct($message)
    {
        $this->message = $message;
    }

    public function __toString()
    {
        return "statusMessage[" . $this->message . "]";
    }

    public function execute(ezcWorkflowExecution $execution)
    {
        echo "Status: " . $this->message . "\n";
        return true;
    }
}

require_once "src/CouchWorkflow/CouchClient.php";
require_once "src/CouchWorkflow/DefinitionStorage.php";
require_once "src/CouchWorkflow/Execution.php";
require_once "src/CouchWorkflow/Workflow.php";
require_once "src/CouchWorkflow/CouchHttpException.php";

$workflow = new CouchWorkflow_Workflow('makeFriends');

$endMerge = new ezcWorkflowNodeSimpleMerge();
$endMerge->addOutNode($workflow->endNode);

// one possible end!
$beginFriendship = new ezcWorkflowNodeSimpleMerge();
$beginFriendshipEnd = new ezcWorkflowNodeAction(array('class' => 'SheldonsFriendAlgo_StatusMessage', 'arguments' => array('BEGIN FRIENDSHIP!')));
$beginFriendshipEnd->addOutNode($endMerge);
$beginFriendship->addOutNode($beginFriendshipEnd);

// another (bad) end!
$noFriend = new ezcWorkflowNodeAction(array('class' => 'SheldonsFriendAlgo_StatusMessage', 'arguments' => array('We wont be friends! :(')));
$noFriend->addOutNode($endMerge);

// 1. Sheldons call your phonenumber
$pickupPhone = new ezcWorkflowNodeInput(array(
    'pickPhone' => new ezcWorkflowConditionIsBool(),
));
$workflow->startNode->addOutNode($pickupPhone);

// 3. Is the Person Home, yes or no?
$pickupChoice = new ezcWorkflowNodeExclusiveChoice();
$pickupPhone->addOutNode($pickupChoice);

// 4a. If Person is Home, ask for a Meal
$askForMeal = new ezcWorkflowNodeInput(array(
    "wouldShareMeal" => new ezcWorkflowConditionIsBool(),
));
$mergeAskForMeal = new ezcWorkflowNodeSimpleMerge();
$mergeAskForMeal->addOutNode($askForMeal);

// 5. If callback happens, go to 4a. ask for a meal!
$waitForCallback = new ezcWorkflowNodeInput(array(
    'doCallback' => new ezcWorkflowConditionIsTrue(),
));
$waitForCallback->addOutNode($mergeAskForMeal);

$pickupChoice->addConditionalOutNode(new ezcWorkflowConditionVariable('pickPhone', new ezcWorkflowConditionIsTrue()), $mergeAskForMeal, $waitForCallback);

// 6. Listen to answer of other person, yes or no?
$mealChoice = new ezcWorkflowNodeExclusiveChoice();
$askForMeal->addOutNode($mealChoice);

// 7a. If the person said yes, go get something to eat and begin friendship!
$goEat = new ezcWorkflowNodeAction(array('class' => 'SheldonsFriendAlgo_StatusMessage', 'arguments' => array('Go eat together')));
$goEat->addOutNode($beginFriendship);

// 7b. If the person said no, ask another question: "Do you enjoy a hot beverage?"
$askForHotBeverage = new ezcWorkflowNodeInput(array(
    "enjoyHotBeverage" => new ezcWorkflowConditionIsBool()
));

// assemble 6 -> 7a or 6 -> 7b
$mealChoice->addConditionalOutNode(
    new ezcWorkflowConditionVariable('wouldShareMeal', new ezcWorkflowConditionIsTrue()),
    $goEat,
    $askForHotBeverage
);

// 8. Beverage Choice, listen to answer of other person, yes or no?
$beverageChoice = new ezcWorkflowNodeExclusiveChoice();
$askForHotBeverage->addOutNode($beverageChoice);

// 9a. If the person said yes, go get something to drink and begin friendship!
$goDrink = new ezcWorkflowNodeAction(array('class' => 'SheldonsFriendAlgo_StatusMessage', 'arguments' => array('Go drink tea together')));
$goDrink->addOutNode($beginFriendship);

// 9b. If the person said no, start with the least objectionable action loop
$loopStartInitActionCounter = new ezcWorkflowNodeVariableSet(array('actionCounter' => 0));

$beverageChoice->addConditionalOutNode(
    new ezcWorkflowConditionVariable('enjoyHotBeverage', new ezcWorkflowConditionIsTrue()),
    $goDrink,
    $loopStartInitActionCounter
);

// 10. ZEH LOOP!
$loop = new ezcWorkflowNodeLoop;
$loopStartInitActionCounter->addOutNode($loop);

$incrementActionCounter = new ezcWorkflowNodeVariableAdd(array('name' => 'actionCounter', 'operand' => 1)); // Increment doent work! :-/
$askForRecreationalActivity = new ezcWorkflowNodeInput(array(
    "recreationalActivity" => new ezcWorkflowConditionIsString(),
));
$incrementActionCounter->addOutNode($askForRecreationalActivity);

$sheldonEvaluatesActivity = new ezcWorkflowNodeAction(array('class' => 'SheldonsFriendAlgo_EvaluateActivity'));
$askForRecreationalActivity->addOutNode($sheldonEvaluatesActivity);

$performActivity = new ezcWorkflowNodeAction(array('class' => 'SheldonsFriendAlgo_StatusMessage', 'arguments' => array('Parttake in Interest!')));
$performActivity->addOutNode($endMerge);

// reset recreational activity variable before entering loop start again
$unsetActivity = new ezcWorkflowNodeVariableUnset('recreationalActivity');
$unsetActivity->addOutNode($loop);

$sheldonLikesActivityChoice = new ezcWorkflowNodeExclusiveChoice();
$sheldonLikesActivityChoice->addConditionalOutNode(
    new ezcWorkflowConditionVariable('sheldonLikesActivity', new ezcWorkflowConditionIsTrue()),
    $performActivity,
    $unsetActivity
);
$sheldonEvaluatesActivity->addOutNode($sheldonLikesActivityChoice);

$continueActionCounter  = new ezcWorkflowConditionVariable( 'actionCounter', new ezcWorkflowConditionIsLessThan( 3 ) );
$loop->addConditionalOutNode( $continueActionCounter, $incrementActionCounter );

$leastObjectionableActivity = new ezcWorkflowNodeAction(array('class' => 'SheldonsFriendAlgo_LeastObjectionableActivity'));
$leastObjectionableActivity->addOutNode($endMerge);

$breakActionCounter     = new ezcWorkflowConditionVariable( 'actionCounter', new ezcWorkflowConditionIsEqual( 3 ) );
$loop->addConditionalOutNode( $breakActionCounter,    $leastObjectionableActivity );

// SAVE THE WORKFLOW
$client = new CouchWorkflow_CouchClient('localhost', 'workflow', 5984);
$storage = new CouchWorkflow_DefinitionStorage($client);
$storage->save($workflow);

// Generate GraphViz/dot markup for workflow "Test".
$visitor = new ezcWorkflowVisitorVisualization;
$workflow->accept( $visitor );
file_put_contents("/tmp/workflow1.dot", (string)$visitor);

echo "Saved Sheldons Friend Making Algorithm: " . $workflow->id . "\n";

$workflow = $storage->loadById($workflow->id);

$visitor = new ezcWorkflowVisitorVisualization;
$workflow->accept( $visitor );
file_put_contents("/tmp/workflow2.dot", (string)$visitor);

interface SheldonsFriendAlgo_InputHandler
{
    public function isInteractive();

    public function askForInput($variable);

    public function filterAndValidate($variable, $data);
}

abstract class SheldonsFriendAlgo_AbstractInputHandler implements SheldonsFriendAlgo_InputHandler
{
    public function filterAndValidate($variable, $data)
    {
        $data = $this->filter($variable, $data);
        if (!$this->isValid($variable, $data)) {
            throw new RuntimeException("Invalid input specified.");
        }
        return $data;
    }

    abstract protected function filter($variable, $data);

    abstract protected function isValid($variable, $data);
}

class SheldonsFriendAlgo_EzConsoleInput implements SheldonsFriendAlgo_InputHandler
{
    private $output;
    private $questions = array();

    public function  __construct()
    {
        $this->output = new ezcConsoleOutput();
        $this->questions['pickPhone']  = ezcConsoleQuestionDialog::YesNoQuestion($this->output, "Sheldon calls you, would you pick up?", "y");
        $this->questions['doCallback'] = ezcConsoleQuestionDialog::YesNoQuestion($this->output, "Sheldon left you a message, do you want to call back now?", "y");
        $this->questions['wouldShareMeal'] = ezcConsoleQuestionDialog::YesNoQuestion($this->output, "Would you like to share a meal?", "y");
        $this->questions['enjoyHotBeverage'] = ezcConsoleQuestionDialog::YesNoQuestion($this->output, "Do you enjoy a hot beverage?", "y");
        
        $this->questions['recreationalActivity'] = new ezcConsoleQuestionDialog($this->output);
        $this->questions['recreationalActivity']->options->text = "Tell me one of your interests?";
        $this->questions['recreationalActivity']->options->showResults = true;
    }

    public function askForInput($variable)
    {
        return ezcConsoleDialogViewer::displayDialog( $this->questions[$variable] );
    }

    public function filterAndValidate($variable, $data)
    {
        switch ($variable) {
            case 'pickPhone':
            case 'doCallback':
            case 'wouldShareMeal':
            case 'enjoyHotBeverage':
                return ($data == 'y') ? true : false;
            case 'recreationalActivity':
                return $data;
        }
    }

    public function isInteractive()
    {
        return true;
    }
    
}

if (!$workflow) {
    $workflow = $storage->loadById('666a10634d43e5c38f946f3f50cd7673');
}


$waitingForInput = array(
    "phonenumber" => "intval",
    "isCallback"  => "boolval",
    "recreationalActivity" => "strval",
);

$execution = new CouchWorkflow_Execution($client, null);
$execution->workflow = $workflow;
$executionId = $execution->start();

echo "Execution: " . $executionId . "\n";

$inputHandler = new SheldonsFriendAlgo_EzConsoleInput();

$i = 0;
do {
    $execution = new CouchWorkflow_Execution($client, $executionId);
    $waitingFor = $execution->getWaitingFor();
    $resumeData = array();

    // prompt user for input!
    foreach ($waitingFor AS $name => $condition) {
        $resumeData[$name] = $inputHandler->askForInput($name);
        if ($inputHandler->isInteractive()) {
            $resumeData[$name] = $inputHandler->filterAndValidate($name, $resumeData[$name]);
        } else {
            break; // stop everything please, we need to wait for the prompt
        }
    }

    try {
        $execution->resume($resumeData);
    } catch(ezcWorkflowInvalidInputException $e) {
        echo "Invalid Input: " . $e->getMessage() . "\n";
        $execution->suspend();
    }
    $i++;

    if ($i > 100) {
        break;
    }
} while ($execution->isSuspended());

echo "Loops: " . $i . "\n";
