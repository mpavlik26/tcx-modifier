<?php

  require_once ('jpgraph/src/jpgraph.php');
  require_once ('jpgraph/src/jpgraph_line.php');

  $fileFromForm = $_FILES["fileToUpload"];
  $uploadsDir = "../uploads";
  $targetDir = "../target";
  $targetImagesDir = $targetDir . "/images";
  $uploadFileName = $uploadsDir . "/" . $fileFromForm["name"];
  $targetFileName = $targetDir . "/" . $fileFromForm["name"];


  //INPUT PARAMETERS - begin:
  
  if($checkbox_preserveJustXth = inputCheckboxChecked("preserveJustXth"))
    $xth = inputParamsNvl("xth", 1);
  
  $timestampsIntervalDefined = false;
  $checkbox_setHR = inputCheckboxChecked("setHR");
  $checkbox_shiftPositions = inputCheckboxChecked("shiftPositions");  
  if($checkbox_setHR || $checkbox_shiftPositions){
    $timestampFromAsText = $_POST["timestampFrom"];
    $timestampToAsText = $_POST["timestampTo"];

    if(!isset($timestampFromAsText) || !isset($timestampToAsText))
      echo "'From' or 'To' timestamp were not specified";
    else{
      $timestampFrom = (new DateTime($timestampFromAsText))->getTimestamp();
      $timestampTo = (new DateTime($timestampToAsText))->getTimeStamp();
      
      if($timestampFrom > $timestampTo)
        echo "'From' timestamp (" . $timestampFromAsText . ") is greater than 'To' timestamp (" . $timestampToAsText . ")";
      else
        $timestampsIntervalDefined = true;
    }
  }
   
  $hr = 0;
  $shiftLatitude = 0;
  $shiftLongitude = 0;
  
  if($timestampsIntervalDefined){
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
  
  if($timestampsIntervalDefined)
    $tcx->modifyTrackPoints($timestampFrom, $timestampTo, $hr, $shiftLatitude, $shiftLongitude);

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

    
    function modifyTrackPoints($timestampFrom, $timestampTo, $hr, $latitudeShift, $longitudeShift){
      $trackPoints = new TrackPoints($this);
      
      $trackPoints->preserveJustTrackPointsWithinTimestampsInterval($timestampFrom, $timestampTo);

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
  
  
  class TrackPoints{
    public $trackPoints; //array of TrackPoint
    public $tcxFile;
    
    
    function __construct($tcxFile){
      $this->tcxFile = $tcxFile;
      
      $this->trackPoints = array(); 
      $trackPointNodes = $this->getXpathEngine()->query("//n:Trackpoint");
      
      foreach($trackPointNodes as $trackPointNode)
        $this->add(new TrackPoint($trackPointNode, $this));
    }
    
    
    function add($trackPoint){
      $this->trackPoints[$trackPoint->getTimestamp()] = $trackPoint; 
    }

    
    function applyMethodOnTrackPoints($trackPointMethodName, $param){
      foreach($this->trackPoints as $trackPoint){
        $trackPoint->$trackPointMethodName($param);
      }
    }
    

    function getArrayByTrackPointMethod($trackPointMethodName){
      $ret = array();
      
      foreach($this->trackPoints as $trackPoint)
        array_push($ret, $trackPoint->$trackPointMethodName());
      
      return $ret;
    }
    
    
    function count(){
      return count($this->trackPoints);   
    }
    
    
    function displayGraph(){
      $hrs = $this->getArrayByTrackPointMethod("getHR");
      $altitudes = $this->getArrayByTrackPointMethod("getAltitude");
      $xs = $this->getArrayByTrackPointMethod("getTime");
      
      $graphWidth = 1800;
      // Create the graph. These two calls are always required
      $graph = new Graph($graphWidth, 500);
      $graph->SetMargin(30,100,20,100);
      $graph->SetScale('textlin');
      $graph->xaxis->SetTickLabels($xs);
      $graph->xaxis->SetTextTickInterval(ceil(20 / ($graphWidth / count($xs))));
      $graph->xaxis->SetLabelAngle(90);
 
      // Create the linear plot
      $linePlotHR = new LinePlot($hrs);
      $graph->Add($linePlotHR);
      
      $linePlotAltitude = new LinePlot($altitudes);
      $graph->SetYScale(0, "lin");
      $graph->AddY(0, $linePlotAltitude);
      $graph->ynaxis[0]->SetColor('black');

      // Display the graph
      displayGraph($graph);
    }
    
    
    function getXpathEngine(){
      return $this->tcxFile->xpathEngine; 
    }
    
    
    function preserveJustTrackPointsWithinTimestampsInterval($timestampFrom, $timestampTo){
      foreach($this->trackPoints as $trackPoint){
        $timestamp = $trackPoint->getTimestamp();
        
        if($timestamp < $timestampFrom || $timestamp > $timestampTo)
          unset($this->trackPoints[$timestamp]);
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
    
    function __construct($domElement, $trackPoints){
      $this->domElement = $domElement;
      $this->trackPoints = $trackPoints;
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
    
    
    function getTime(){
      return $this->getDateTime()->format("H:i:s");
    }
    
    
    function getTimestamp(){//timestamp is a key in the array once trackpoints are organized in Trackpoints object
      return $this->getDateTime()->getTimestamp();  
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