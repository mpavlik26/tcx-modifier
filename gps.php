<?php

require_once "c:\\users\\Martin Pavl�k\\vendor\\autoload.php";

/*
use Phpml\Regression\LeastSquares;

$watts = [[1, 500], [2, 450], [7, 400], [8, 420], [10, 500]];
$hrs = [160, 150, 140, 144, 165];

$ls = new LeastSquares();
$ls->train($watts, $hrs);

echo $ls->predict([6, 450]);
*/

  require_once ('jpgraph/src/jpgraph.php');
  require_once ('jpgraph/src/jpgraph_line.php');

  $fileFromForm = $_FILES["fileToUpload"];
  $uploadsDir = "../uploads";
  $targetDir = "../target";
  $targetImagesDir = $targetDir . "/images";
  $uploadFileName = $uploadsDir . "/" . $fileFromForm["name"];
  $targetFileName = $targetDir . "/" . $fileFromForm["name"];
  $movingAverageWindowSizeInSecond = 45; //it's used e.g. for moving average Watts ....
  

  //INPUT PARAMETERS - begin:
  
  if($checkbox_preserveJustXth = inputCheckboxChecked("preserveJustXth"))
    $xth = inputParamsNvl("xth", 1);
  
  $checkbox_setHR = inputCheckboxChecked("setHR");
  $checkbox_shiftPositions = inputCheckboxChecked("shiftPositions");  
  $timestampIntervalForModification = new TimestampInterval();

  if($checkbox_setHR || $checkbox_shiftPositions){
    $timestampIntervalForModification->initFromParameters("timestampFrom", "timestampTo");
    
    if($timestampIntervalForModification->isError())
      echo "There's a problem in specification of interval for modification: " . $timestampIntervalForModification->getErrorMessage() . "<br>\n";
                                                                                                  
    if($checkbox_setHR){
      $timestampIntervalForTraining = new TimestampInterval();
      $timestampIntervalForTraining->initFromParameters("trainingTimestampFrom", "trainingTimestampTo");
      
      if($timestampIntervalForTraining->getError() == TimestampIntervalFromParametersErrorEnum::timestampFromIsGreaterThanTimestampTo)
        echo "There's a problem in specification of interval for training: " . $timestampIntervalForTraining->getErrorMessage() . "<br>\n";
    }
  } 
   
  $hr = 0;
  $shiftLatitude = 0;
  $shiftLongitude = 0;
  
  if(!($timestampIntervalForModification->isError())){
    if($checkbox_setHR)
      $hr = inputParamsNvl("HR", 0);
    
    if($checkbox_shiftPositions){
      $shiftLatitude = inputParamsNvl("shiftLatitude", 0);
      $shiftLongitude = inputParamsNvl("shiftLongitude", 0);
    }
  }
  
  echo "<br/>\n";
  //INPUT PARAMETERS - end

  
  if(!move_uploaded_file($fileFromForm["tmp_name"], $uploadFileName))
    exit("Unable to save uploaded file to uploads dir");

    
  $tcx = new TCXFile($uploadFileName);
  $tcx->displayGraph();
  
  if($checkbox_preserveJustXth)
    $tcx->preserveJustXth($xth);
  
  if(!($timestampIntervalForModification->isError()))
    $tcx->modifyTrackPoints($timestampIntervalForModification, $checkbox_setHR, $hr, $shiftLatitude, $shiftLongitude);

  $tcx->displayGraph();
  
  $tcx->save($targetFileName);
  
  echo "Done";
  
  
  class TCXFile{
    public $xml;
    public $xpathEngine;
    
    
    function __construct($fileName){
      $this->xml = new DomDocument();
      if(!$this->xml->load($fileName))
        exit("Unable to parse file '" . $fileName . "'");

      $this->xpathEngine = new DOMXpath($this->xml);
      $this->xpathEngine->registerNamespace("n", "http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2");
      $this->xpathEngine->registerNamespace("e", "http://www.garmin.com/xmlschemas/ActivityExtension/v2");
    }


    function displayGraph(){
      (new TrackPoints($this))->displayGraph();
    }
    
    
    function preserveJustXth($xth){
      $tracks = $this->xpathEngine->query("//n:Track");
      
      foreach($tracks as $track){
        $trackpoints = $this->xpathEngine->query("n:Trackpoint", $track);
        //print_r($trackpoints);
        
        $i = 0;
        foreach($trackpoints as $trackpoint){
          if(($i % $xth && $i != $trackpoints->length - 1)){//preserve first, xth and last trackpoint elements
            $parentNode = $trackpoint->parentNode;
            $parentNode->removeChild($trackpoint);
          } 
          
          $i++;
        }
      }
    }

    
    function modifyTrackPoints($timestampInterval, $checkbox_setHR, $hr, $latitudeShift, $longitudeShift){
      $trackPoints = new TrackPoints($this);
      
      $trackPoints->preserveJustTrackPointsWithinTimestampsInterval($timestampInterval);

      if($checkbox_setHR)
        $trackPoints->setProgressiveHR($hr);
      
      if($latitudeShift)
        $trackPoints->applyMethodOnTrackPoints("shiftLatitude", $latitudeShift);
      
      if($longitudeShift)
        $trackPoints->applyMethodOnTrackPoints("shiftLongitude", $longitudeShift);
      
      echo $trackPoints->count() . " track point(s) were/was modified<br/>\n";
    }
    
    
    function save($fileName){
      $this->xml->save($fileName); 
    }
  }

  
  class TimestampInterval{
    protected $timestampFrom;
    protected $timestampTo;
    protected $error; //enum of class TimestampIntervalFromParametersErrorEnum
    
    
    function __construct(){
      $this->timestampFrom = null;
      $this->timestampTo = null;
      $this->error = TimestampIntervalFromParametersErrorEnum::someOrBothBorderValuesOfIntervalNotDefined;
    }
    
    
    function initFromParameters($timestampFromParameterName, $timestampToParameterName){
      $timestampFromAsText = $GLOBALS["_POST"][$timestampFromParameterName];
      $timestampToAsText = $GLOBALS["_POST"][$timestampToParameterName];
      $this->initFromTextValues($timestampFromAsText, $timestampToAsText);
    } 
    
    
    function initFromTextValues($timestampFromAsText, $timestampToAsText){
      if(!isset($timestampFromAsText) || !isset($timestampToAsText) || $timestampFromAsText == "" || $timestampToAsText == "")
        $this->error = TimestampIntervalFromParametersErrorEnum::someOrBothBorderValuesOfIntervalNotDefined;
      else
        $this->initFromTimestamps((new DateTime($timestampFromAsText))->getTimestamp(), (new DateTime($timestampToAsText))->getTimeStamp());
    }
    
      
    function initFromTimestamps($timestampFrom, $timestampTo){
      $this->timestampFrom = $timestampFrom;
      $this->timestampTo = $timestampTo;
    
      $this->error = ($this->timestampFrom > $this->timestampTo) ? TimestampIntervalFromParametersErrorEnum::timestampFromIsGreaterThanTimestampTo : TimestampIntervalFromParametersErrorEnum::noError;
    }

    
    function getErrorMessage(){
      switch($this->error){
        case TimestampIntervalFromParametersErrorEnum::someOrBothBorderValuesOfIntervalNotDefined: return "'From' or 'To' timestamp were not specified.";
        case TimestampIntervalFromParametersErrorEnum::timestampFromIsGreaterThanTimestampTo: return "'From' timestamp is greater than 'To' timestamp.";
        default: return "";
      }
    }

    
    function getTimestampFrom(){
      return ($this->error) ? null : $this->timestampFrom;
    }

    
    function getTimestampTo(){
      return ($this->error) ? null : $this->timestampTo;
    }
    
    
    function getError(){
      return $this->error; 
    }
    
    
    function isError(){
      return ($this->error == TimestampIntervalFromParametersErrorEnum::noError) ? false : true; 
    }
  }


  abstract class TimestampIntervalFromParametersErrorEnum{
    const noError = 0;
    const someOrBothBorderValuesOfIntervalNotDefined = -1;
    const timestampFromIsGreaterThanTimestampTo = -2;
  }  

  
  class TrackPoints{
    public $trackPoints; //array of TrackPoint
    public $tcxFile;
    
    
    function __construct($tcxFile){
      $this->tcxFile = $tcxFile;
      
      $this->trackPoints = array(); 
      $trackPointNodes = $this->getXpathEngine()->query("//n:Trackpoint");
      
      foreach($trackPointNodes as $trackPointNode)
        $this->add(new TrackPoint($trackPointNode, $this));
      
      $this->countMovingAverageWatts();
    }
    
    
    function add($trackPoint){
      $this->trackPoints[$trackPoint->getTimestamp()] = $trackPoint; 
    }

    
    function applyMethodOnTrackPoints($trackPointMethodName, $param){
      foreach($this->trackPoints as $trackPoint){
        $trackPoint->$trackPointMethodName($param);
      }
    }
    
    
    function areWattsAvailable(){
      return ($this->count()) ? reset($this->trackPoints)->areWattsAvailable() : false;
    }
    

    function countMovingAverageWatts(){
      if($this->areWattsAvailable()){
        $sum = 0;
        $count = 0;
        $leftBorderOfMovingWindowArray = $this->trackPoints;
        $leftBorderOfMovingWindow = reset($leftBorderOfMovingWindowArray);
        
        foreach($this->trackPoints as $trackPoint){
          $sum += $trackPoint->getWatts();
          $count++;
          
          while($trackPoint->getTimestamp() - $GLOBALS["movingAverageWindowSizeInSecond"] > $leftBorderOfMovingWindow->getTimestamp()){
            $sum -= $leftBorderOfMovingWindow->getWatts();
            $count--;
            $leftBorderOfMovingWindow = next($leftBorderOfMovingWindowArray);
          }
          
          $trackPoint->setMovingAverageWatts(round($sum / $count));
        }
      }
    }
    
    
    function count(){
      return count($this->trackPoints);   
    }
    
    
    function displayGraph(){
      $xs = $this->getArrayByTrackPointMethod("getTime");
      $graphWidth = 1800;

      $graph = new Graph($graphWidth, 500);
      $graph->SetMargin(30,140,20,100);
      $graph->SetScale('textlin');
      $graph->xaxis->SetTickLabels($xs);
      $graph->xaxis->SetTextTickInterval(ceil(20 / ($graphWidth / count($xs))));
      $graph->xaxis->SetLabelAngle(90);

      $hrs = $this->getArrayByTrackPointMethod("getHR");
      $linePlotHR = new LinePlot($hrs);
      $graph->Add($linePlotHR);
      $linePlotHR->SetColor('red');
      
      $altitudes = $this->getArrayByTrackPointMethod("getAltitude");
      $linePlotAltitude = new LinePlot($altitudes);
      $graph->SetYScale(0, "lin");
      $graph->AddY(0, $linePlotAltitude);
      $linePlotAltitude->SetColor('black');
      $graph->ynaxis[0]->SetColor('black');
      
      if($this->areWattsAvailable()){
        $watts = $this->getArrayByTrackPointMethod("getWatts");
        $linePlotWatts = new LinePlot($watts);
        $graph->SetYScale(1, "lin");
        $graph->AddY(1, $linePlotWatts);
        $linePlotWatts->SetColor('green');
        $graph->ynaxis[1]->SetColor('green');
        
        $movingAverageWatts = $this->getArrayByTrackPointMethod("getMovingAverageWatts");
        $linePlotMovingAverageWatts = new LinePlot($movingAverageWatts);
        $graph->SetYScale(2, "lin");
        $graph->AddY(2, $linePlotMovingAverageWatts);
        $linePlotMovingAverageWatts->SetColor('magenta');
        $graph->ynaxis[2]->SetColor('magenta');
        
      }
      
      displayGraph($graph);
    }
    
    
    function getArrayByTrackPointMethod($trackPointMethodName){
      $ret = array();
      
      foreach($this->trackPoints as $trackPoint)
        array_push($ret, $trackPoint->$trackPointMethodName());
      
      return $ret;
    }
    
    
    function getAggregationByTrackPointMethod($aggregationMethod, $trackPointMethodName){//example of usage: getAggregationByTrackPointMethod("min", "getHR"); ... finds the minimal HR
      $values = $this->getArrayByTrackPointMethod($trackPointMethodName);
      
      return $aggregationMethod($values);
    }

    
    function getXpathEngine(){
      return $this->tcxFile->xpathEngine; 
    }
                                                                          
    
    function preserveJustTrackPointsWithinTimestampsInterval($timestampInterval){
      foreach($this->trackPoints as $trackPoint){
        $timestamp = $trackPoint->getTimestamp();
        
        if($timestamp < $timestampInterval->getTimestampFrom() || $timestamp > $timestampInterval->getTimestampTo())
          $this->remove($trackPoint);
      }
    }
    

    function remove($trackPoint){
      unset($this->trackPoints[$trackPoint->getTimestamp()]); 
    }
   
    
    function setHRAccordingToMovingAverageWatts($hr){//if ($hr == 0) then HR of the last trackPoint is used instead
      if($this->areWattsAvailable()){
        $firstTrackPoint = reset($this->trackPoints);
        $lastTrackPoint = end($this->trackPoints);
        
        if($hr)
          $lastTrackPoint->setHR($hr);
        
        $firstHR = $firstTrackPoint->getHR();
        $lastHR = $lastTrackPoint->getHR();
        
        $firstMAWatts = $firstTrackPoint->getMovingAverageWatts();
        $lastMAWatts = $lastTrackPoint->getMovingAverageWatts();
        $maxMAWatts = $this->getAggregationByTrackPointMethod("max", "getMovingAverageWatts");
        $minMAWatts = $this->getAggregationByTrackPointMethod("min", "getMovingAverageWatts");
        $spreadMAWatts = $maxMAWatts - $minMAWatts;
        
      }
      
      $trackPointsCount = $this->count();
      
      if($trackPointsCount){
        
        $step = ($lastHR - $firstHR) / ($trackPointsCount - 1);
        
        $hr = $firstHR;
        foreach($this->trackPoints as $trackPoint){
          $trackPoint->setHR(round($hr));
          $hr += $step;
        }
      }
    }
    
    
    function setProgressiveHR($hr){//if ($hr == 0) then HR of the last trackPoint is used instead
      $trackPointsCount = $this->count();
      
      if($trackPointsCount){
        $firstHR = reset($this->trackPoints)->getHR();
        $lastHR = ($hr) ? $hr : end($this->trackPoints)->getHR();
        
        $step = ($lastHR - $firstHR) / ($trackPointsCount - 1);
        
        $hr = $firstHR;
        foreach($this->trackPoints as $trackPoint){
          $trackPoint->setHR(round($hr));
          $hr += $step;
        }
      }
    }
    
  }
  
  
  class TrackPoint{
    public $domElement;
    public $trackPoints;
    public $movingAverageWatts;
    
    function __construct($domElement, $trackPoints){
      $this->domElement = $domElement;
      $this->trackPoints = $trackPoints;
    }

    
    function areWattsAvailable(){
      try{
        $this->getWatts();
        return true;
      }
      catch(Exception $e){
        return false;
      }
    }
    
    
    function getAltitude(){
      return $this->getElement("n:AltitudeMeters")->nodeValue;
    }

    
    function getDateTime(){
      $timeNode = ($this->getXpathEngine()->query("n:Time", $this->domElement))[0]; 
      return new DateTime($timeNode->textContent);
    }
    
    
    function getElement($xpath){
      return ($this->getXpathEngine()->query($xpath, $this->domElement))[0];
    }
    
    
    function getHR(){
      return $this->getElement("n:HeartRateBpm/n:Value")->nodeValue;
    }
    
    
    function getMovingAverageWatts(){
      return $this->movingAverageWatts; 
    }
    
    
    function getTime(){
      return $this->getDateTime()->format("H:i:s");
    }
    
    
    function getTimestamp(){//timestamp is a key in the array once trackpoints are organized in Trackpoints object
      return $this->getDateTime()->getTimestamp();  
    }

    
    function getWatts(){
      return $this->getElement("n:Extensions/e:TPX/e:Watts")->nodeValue;
    }
    
    
    function getXpathEngine(){
      return $this->trackPoints->getXpathEngine(); 
    }
    
    
    function setElementValue($xpath, $value, $isShift = false){//if $isShift is set to true, then new value = old value + $value 
      $element = $this->getElement($xpath);
      $element->nodeValue = $value + (($isShift) ? $element->nodeValue : 0);
    }
    
    
    function shiftElementValue($xpath, $value){
      $this->setElementValue($xpath, $value, true); 
    }
    
    
    function setHR($hr){
      if($hr)
        $this->setElementValue("n:HeartRateBpm/n:Value", $hr);  
    }
    
    
    function setMovingAverageWatts($movingAverageWatts){
      $this->movingAverageWatts = $movingAverageWatts;  
    }
    

    function shiftLatitude($shift){
      if($shift)
        $this->shiftElementValue("n:Position/n:LatitudeDegrees", $shift); 
    }
   
    
    function shiftLongitude($shift){
      if($shift)
        $this->shiftElementValue("n:Position/n:LongitudeDegrees", $shift); 
    }
  }
  
  
  function displayGraph($graph){
    $imgFileName = $GLOBALS["targetImagesDir"] . "/chart_" . rand(0,999999) . ".png";
    $graph->Stroke($imgFileName);
    echo "<img src=\"" . $imgFileName . "\"/><br/><br/>\n";
  }
  
  
  function inputParamsNvl($name, $nvlValue){
    $post = $GLOBALS["_POST"];
    return (isset($post[$name])) ? $post[$name] : $nvlValue;
  }
  
  
  function inputCheckboxChecked($name){
    return (isset($GLOBALS["_POST"][$name]));
  }

  
  
  
?>