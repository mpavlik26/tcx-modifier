<?php

  require_once "c:\\users\\Martin Pavlík\\vendor\\autoload.php";

  use Phpml\Regression\LeastSquares;

  require_once ('jpgraph/src/jpgraph.php');
  require_once ('jpgraph/src/jpgraph_line.php');
  require_once ('jpgraph/src/jpgraph_plotline.php');

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
  $timestampIntervalForTraining = new TimestampInterval();
  
  if($checkbox_setHR || $checkbox_shiftPositions){
    $timestampIntervalForModification->initFromParameters("timestampFrom", "timestampTo");
    
    if($timestampIntervalForModification->isError())
      echo "There's a problem in specification of interval for modification: " . $timestampIntervalForModification->getErrorMessage() . "<br>\n";
                                                                                                  
    if($checkbox_setHR){
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

    
  $tcx = new TCXFile($uploadFileName, $timestampIntervalForModification);
  $tcx->displayGraph();
  
  print_r($tcx->getHRAnomalies());
  
  if($checkbox_preserveJustXth)
    $tcx->preserveJustXth($xth);
  
  $tcx->modifyTrackPoints($checkbox_setHR, $hr, $timestampIntervalForTraining, $shiftLatitude, $shiftLongitude);

  $tcx->displayGraph();
  
  $tcx->save($targetFileName);
  
  echo "Done";
  
  class _Array{
    public $items;
    
    
    function __construct(){
      $this->items = array(); 
    }
    
    
    function addItem($item){
      array_push($this->items, $item); 
    }

    
    function applyMethodOnItems($itemMethodName, $param){
      foreach($this->items as $item){
        $item->$itemMethodName($param);
      }
    }
    
    
    function count(){
      return count($this->items);   
    }

    
    function getArrayByItemMethods($itemMethodNames){//it returns array of values returned by item::callMethods($trackPointMethodNames) calls - see definition of that method to understand the fact $itemMethodNames can be either a stringname of one method or an array of method names, or array of array ... and this everything influence the structure of the appropriate return value
      $ret = array();
      
      foreach($this->items as $item)
        array_push($ret, $item->callMethods($itemMethodNames));
      
      return $ret;
    }
    
    
    function setItem($key, $item){
      $this->items[$key] = $item; 
    }
    
    
    function setItemUsingMethod($item, $method){
      $this->items[$item->$method()] = $item; 
    }
  }
  
  
  
  class HRAnomalies extends _Array{
    function __construct(){
      parent::__construct(); 
    }
  }
  
  
  class HRAnomaly{
    public $timestampInterval; //object of TimestampInterval class
    
    
    function __construct($timestampInterval){
      $this->timestampInterval = $timestampInterval;
    }
  }
  
  
  class TCXFile{
    public $timestampIntervalForModification;
    public $xml;
    public $xpathEngine;
    
    
    function __construct($fileName, $timestampIntervalForModification){
      $this->xml = new DomDocument();
      if(!$this->xml->load($fileName))
        exit("Unable to parse file '" . $fileName . "'");

      $this->xpathEngine = new DOMXpath($this->xml);
      $this->xpathEngine->registerNamespace("n", "http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2");
      $this->xpathEngine->registerNamespace("e", "http://www.garmin.com/xmlschemas/ActivityExtension/v2");
      
      $this->timestampIntervalForModification = $timestampIntervalForModification;
    }


    function displayGraph(){
      (new TrackPoints($this))->displayGraph();
    }
    
    
    function getHRAnomalies(){
      return (new TrackPoints($this))->getHRAnomalies();
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

    
    function modifyTrackPoints($checkbox_setHR, $hr, $timestampIntervalForTraining, $latitudeShift, $longitudeShift){
      if($this->timestampIntervalForModification->isError()) //if there's no timestamp interval for modification defined, no modification occurs
        return;
      
      $trackPoints = new TrackPoints($this);
      
      $trackPoints->filterAccordingToTimestampInterval($this->timestampIntervalForModification, true);

      if($checkbox_setHR){
        if($trackPoints->areWattsAvailable() && !$timestampIntervalForTraining->isError())
           $trackPoints->setHRAccordingToMovingAverageWatts($hr, $timestampIntervalForTraining);
        else
          $trackPoints->setProgressiveHR($hr);
      }
      
      if($latitudeShift)
        $trackPoints->applyMethodOnItems("shiftLatitude", $latitudeShift);
      
      if($longitudeShift)
        $trackPoints->applyMethodOnItems("shiftLongitude", $longitudeShift);
      
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
    
    
    function __debugInfo(){
      return ["timestampInterval" => $this->toString()]; 
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
    
    
    function toString($includeDate = true){
      if($this->isError())
        return $this->getErrorMessage();
      else{
        $format = (($includeDate) ? "d.m.Y " : "") .  "H:i:s";
        $from = (new DateTime())->setTimestamp($this->getTimeStampFrom())->format($format);
        $to = (new DateTime())->setTimestamp($this->getTimeStampTo())->format($format);
        
        return $from . " - " . $to;
      }
    }
  }


  abstract class TimestampIntervalFromParametersErrorEnum{
    const noError = 0;
    const someOrBothBorderValuesOfIntervalNotDefined = -1;
    const timestampFromIsGreaterThanTimestampTo = -2;
  }  

  
  class TrackPoints extends _Array{
    public $tcxFile;
    
    
    function __construct($tcxFile){
      parent::__construct();
      
      $this->tcxFile = $tcxFile;
      
      $trackPointNodes = $this->getXpathEngine()->query("//n:Trackpoint");
      
      foreach($trackPointNodes as $trackPointNode)
        $this->setItemUsingMethod(new TrackPoint($trackPointNode, $this), "getTimestamp");
      
      $this->countMovingAverageWatts();
    }
    
    
    function addVerticalLinesToGraph($graph){
      $verticalLines = new VerticalLines($graph, $this->getArrayByItemMethods("getTimestamp"));
      
      $verticalLines->set2ItemsForTimestampInterval($this->tcxFile->timestampIntervalForModification, "green", "red");
        
      $verticalLines->addToGraph();
    }
    
    
    function areWattsAvailable(){
      return ($this->count()) ? reset($this->items)->areWattsAvailable() : false;
    }
    

    function countMovingAverageWatts(){
      if($this->areWattsAvailable()){
        $sum = 0;
        $count = 0;
        $leftBorderOfMovingWindowArray = $this->items;
        $leftBorderOfMovingWindow = reset($leftBorderOfMovingWindowArray);
        
        foreach($this->items as $trackPoint){
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
    
    
    function displayGraph(){
      $xs = $this->getArrayByItemMethods("getTime");
      $graphWidth = 1800;

      $graph = new Graph($graphWidth, 500);
      $graph->SetMargin(30,140,20,100);
      $graph->SetScale('textlin');
      $graph->xaxis->SetTickLabels($xs);
      $graph->xaxis->SetTextTickInterval(ceil(20 / ($graphWidth / count($xs))));
      $graph->xaxis->SetLabelAngle(90);

      $hrs = $this->getArrayByItemMethods("getHR");
      $linePlotHR = new LinePlot($hrs);
      $graph->Add($linePlotHR);
      $linePlotHR->SetColor('red');
      
      $altitudes = $this->getArrayByItemMethods("getAltitude");
      $linePlotAltitude = new LinePlot($altitudes);
      $graph->SetYScale(0, "lin");
      $graph->AddY(0, $linePlotAltitude);
      $linePlotAltitude->SetColor('black');
      $graph->ynaxis[0]->SetColor('black');
      
      if($this->areWattsAvailable()){
        $watts = $this->getArrayByItemMethods("getWatts");
        $linePlotWatts = new LinePlot($watts);
        $graph->SetYScale(1, "lin");
        $graph->AddY(1, $linePlotWatts);
        $linePlotWatts->SetColor('green');                                                                                                                                              
        $graph->ynaxis[1]->SetColor('green');
        
        $movingAverageWatts = $this->getArrayByItemMethods("getMovingAverageWatts");
        $linePlotMovingAverageWatts = new LinePlot($movingAverageWatts);
        $graph->SetYScale(2, "lin");
        $graph->AddY(2, $linePlotMovingAverageWatts);
        $linePlotMovingAverageWatts->SetColor('magenta');
        $graph->ynaxis[2]->SetColor('magenta');
      }
      
      $this->addVerticalLinesToGraph($graph);
      
      displayGraph($graph);
    }
    
    
    function getAggregationByTrackPointMethod($aggregationMethod, $trackPointMethodName){//example of usage: getAggregationByTrackPointMethod("min", "getHR"); ... finds the minimal HR
      $values = $this->getArrayByItemMethods($trackPointMethodName);
      
      return $aggregationMethod($values);
    }

    
    function getHRAnomalies(){
      $minimalHRChangeInTimeRatioForAnomaly = 1; //minimal required ratio between HR change for the given time. Eg.: if it's = 2, then it's necessary HR changes for more than 10 in 5 seconds
      $minimalHRChangeInForAnomaly = 5; // minimal change of HR for anomaly

      $hrAnomalies = new HRAnomalies();
      
      $timeStampHRPairs = $this->getArrayByItemMethods(["getTimeStamp", "getHR"]);
      $timeStampHRPairsCount = count($timeStampHRPairs);
    
      $indexRightShift = 1;
      for($i = 0; $i < $timeStampHRPairsCount; $i += $indexRightShift){
        for($indexRightShift = 1; ($i + $indexRightShift) < $timeStampHRPairsCount; $indexRightShift++){
          $differenceInSeconds = $timeStampHRPairs[$i + $indexRightShift][0] - $timeStampHRPairs[$i][0];
          $differenceInHR = $timeStampHRPairs[$i + $indexRightShift][1] - $timeStampHRPairs[$i][1];
          $ratio = $differenceInHR / $differenceInSeconds;
          
          if(abs($ratio) < $minimalHRChangeInTimeRatioForAnomaly){
            if($differenceInHR >= $minimalHRChangeInForAnomaly){
              if($indexRightShift > 1){
                $timestampInterval = new TimestampInterval();
                $timestampInterval->initFromTimestamps($timeStampHRPairs[$i][0], $timeStampHRPairs[$i + $indexRightShift - 1][0]);
                $hrAnomalies->addItem(new HRAnomaly($timestampInterval));
              }
            }
            break;
          }
        }
      }
      
      return $hrAnomalies;
   }
    
    
    function getXpathEngine(){
      return $this->tcxFile->xpathEngine; 
    }
                                                                          
    
    function filterAccordingToTimestampInterval($timestampInterval, $preserve){//$preserve: true -> only trackPoints in interval remains; false -> only trackPoints not in interval remains
      foreach($this->items as $trackPoint){
        $timestamp = $trackPoint->getTimestamp();
        $isInInterval = ($timestamp >= $timestampInterval->getTimestampFrom() && $timestamp <= $timestampInterval->getTimestampTo());
        
        if($preserve && !$isInInterval || !$preserve && $isInInterval)
          $this->remove($trackPoint);
      }
    }
    

    function remove($trackPoint){
      unset($this->items[$trackPoint->getTimestamp()]); 
    }
   
    
    function setHRAccordingToMovingAverageWatts($hr, $timestampIntervalForTraining){//if ($hr == 0) then HR of the last trackPoint is used instead
      if($this->areWattsAvailable() && !$timestampIntervalForTraining->isError()){
        if($hr)
          end($this->items)->setHR($hr);

        $trainingTrackPoints = new TrackPoints($this->tcxFile);
        $trainingTrackPoints->filterAccordingToTimestampInterval($timestampIntervalForTraining, true);
        
        if($trainingTrackPoints->count() > 0){
          $movingAverageWatts = $trainingTrackPoints->getArrayByItemMethods(["getTimestamp", "getMovingAverageWatts"]);
          $hrs = $trainingTrackPoints->getArrayByItemMethods("getHR");
          
          $ls = new LeastSquares();
          $ls->train($movingAverageWatts, $hrs);
  
          foreach($this->items as $trackPoint){
            $trackPoint->setHR(round($ls->predict([$trackPoint->getTimestamp(), $trackPoint->getMovingAverageWatts()]))); 
          }
        }
        else{
          echo "Training interval doesn't match to input tcx file and that's why progressive setting of HR is used instead of estimation based on watts.<br/>\n";
          $this->setProgressiveHR($hr);
        }
      }
    }
    
    
    function setProgressiveHR($hr){//if ($hr == 0) then HR of the last trackPoint is used instead
      $trackPointsCount = $this->count();
      
      if($trackPointsCount){
        $firstHR = reset($this->items)->getHR();
        $lastHR = ($hr) ? $hr : end($this->items)->getHR();
        
        $step = ($lastHR - $firstHR) / ($trackPointsCount - 1);
        
        $hr = $firstHR;
        foreach($this->items as $trackPoint){
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
      return !is_null($this->getWatts());
    }

    
    function callMethods($methodNames){//$methodNames can be either just one method name or an array of methods or even array of arrays. The return value depends on the object comming to this parameter. If it's scalar value, then just scalar value is returned. If it's an array, then array is returned and if it's array of arrays, then array of arrays is returned and so on ....
      if(is_array($methodNames)){
        $ret = array();
        
        foreach($methodNames as $methodName)
          array_push($ret, $this->callMethods($methodName));
      }
      else
        $ret = $this->$methodNames();
        
      return $ret;
    }
    
    
    function getAltitude(){
      return $this->getElement("n:AltitudeMeters")->nodeValue;
    }

    
    function getDateTime(){ 
      $timeNode = $this->getElement("n:Time");
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
      $element = $this->getElement("n:Extensions/e:TPX/e:Watts");

      return ($element) ? $element->nodeValue : null;
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
  
  
  class VerticalLines extends _Array{
    public $graph;
    public $firstGraphTimestamp;
    
    function __construct($graph, $timestamps){
      parent::__construct();
      
      $this->graph = $graph;
      $this->firstGraphTimestamp = min($timestamps);
    }
    
    
    function set2ItemsForTimestampInterval($timestampInterval, $colorFrom, $colorTo){
      if($timestampInterval->isError())
        return;
      
      $timestampFrom = $timestampInterval->getTimestampFrom();
      $timestampTo = $timestampInterval->getTimestampTo();
      
      $this->setItem($timestampFrom, new VerticalLine($this, $timestampFrom, $colorFrom));
      $this->setItem($timestampTo, new VerticalLine($this, $timestampTo, $colorTo));
    }
      
    
    function addToGraph(){
      foreach($this->items as $verticalLine){
        $verticalLine->addToGraph(); 
      }
    }
  }
  
  
  class VerticalLine{
    public $color;
    public $timestamp;
    public $verticalLines;//parent
    
    
    function __construct($verticalLines, $timestamp, $color){
      $this->verticalLines = $verticalLines;
      $this->timestamp = $timestamp;
      $this->color = $color;
    }

    
    function addToGraph(){
      $this->verticalLines->graph->AddLine(new PlotLine(VERTICAL, ($this->timestamp - $this->verticalLines->firstGraphTimestamp), $this->color, 1)); 
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