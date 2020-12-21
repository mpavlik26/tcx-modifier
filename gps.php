<?php
  $fileFromForm = $_FILES["fileToUpload"];
  $uploadsDir = "../uploads";
  $targetDir = "../target";
  $uploadFileName = $uploadsDir . "/" . $fileFromForm["name"];
  $targetFileName = $targetDir . "/" . $fileFromForm["name"];
  
  //INPUT PARAMETERS - begin:
  if($checkbox_preserveJustXth = isset($_POST["preserveJustXth"]))
    $xth = $_POST["xth"];
  
  $timestampsIntervalDefined = false;
  $checkbox_setHR = isset($_POST["setHR"]);
  $checkbox_shiftPositions = isset($_POST["shiftPositions"]);  
  if($checkbox_setHR || $checkbox_shiftPositions){
    $setHRTimestampFromAsText = $_POST["setHRTimestampFrom"];
    $setHRTimestampToAsText = $_POST["setHRTimestampTo"];

    if(!isset($setHRTimestampFromAsText) || !isset($setHRTimestampFromAsText))
      echo "'From' or 'To' timestamp for setting HR to a new value were not specified";
    else{
      $setHRTimestampFrom = (new DateTime($setHRTimestampFromAsText))->getTimestamp();
      $setHRTimestampTo = (new DateTime($setHRTimestampToAsText))->getTimeStamp();
      
      if($setHRTimestampFrom > $setHRTimestampTo)
        echo "'From' timestamp (" . $setHRTimestampFromAsText . ") is greater than 'To' timestamp (" . $setHRTimestampToAsText . ")";
      else
        $timestampsIntervalDefined = true;
    }
  }
   
  $setHRTo = 0;
  $shiftLatitude = 0;
  $shiftLongitude = 0;
  
  if($timestampsIntervalDefined){
    if($checkbox_setHR){
      if(isset($_POST["setHRTo"]))
        $setHRTo = $_POST["setHRTo"];
      
      if(($setHRTo < 30) || ($setHRTo > 270)){
        echo "New value of HR the system should set is not specified or is out of the allowed range (from 30 to 270 bpm)";
        $setHRTo = 0;
      }
    }
    
    if($checkbox_shiftPositions){
      $shiftLatitude = isset($_POST["shiftLatitude"]) ? $_POST["shiftLatitude"] : 0;
      $shiftLongitude = isset($_POST["shiftLongitude"]) ? $_POST["shiftLongitude"] : 0;
    }
  }
  
  echo "<br/>\n";
  //INPUT PARAMETERS - end

  
  if(!move_uploaded_file($fileFromForm["tmp_name"], $uploadFileName))
    exit("Unable to save uploaded file to uploads dir");

    
  $tcx = new TCXFile($uploadFileName);
  
  if($checkbox_preserveJustXth)
    $tcx->preserveJustXth($xth);
  
  if($timestampsIntervalDefined)
    $tcx->modifyTrackPoints($setHRTimestampFrom, $setHRTimestampTo, $setHRTo, $shiftLatitude, $shiftLongitude);
  
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
    

    function getTrackPointsWithinTimestampsInterval($timestampFrom, $timestampTo){
      $ret = array();
      
      $trackPoints = $this->xpathEngine->query("//n:Trackpoint");
      
      foreach($trackPoints as $trackPoint){
        $timeNode = ($this->xpathEngine->query("n:Time", $trackPoint))[0]; 
        $dateTime = new DateTime($timeNode->textContent);
        $timestamp = $dateTime->getTimestamp();
        
        if($timestamp >= $timestampFrom && $timestamp <= $timestampTo)
          array_push($ret, new TrackPoint($trackPoint, $this->xpathEngine));
      }
      return $ret;
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
      $trackPoints = $this->getTrackPointsWithinTimestampsInterval($timestampFrom, $timestampTo);
      
      foreach($trackPoints as $trackPoint){
        $trackPoint->setHR($hr);
        $trackPoint->shiftLatitude($latitudeShift);
        $trackPoint->shiftLongitude($longitudeShift);
      }
      
      echo count($trackPoints) . " track point(s) were/was modified<br/>\n";
    }
    
    
    function save($fileName){
      $this->xml->save($fileName); 
    }
  }
  
  
  class TrackPoint{
    public $domElement;
    public $xpathEngine;
    
    
    function __construct($domElement, $xpathEngine){
      $this->domElement = $domElement; 
      $this->xpathEngine = $xpathEngine;
    }
    
    
    function setElementValue($xpath, $value, $isShift = false){//if $isShift is set to true, then new value = old value + $value 
      $element = ($this->xpathEngine->query($xpath, $this->domElement))[0];
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
  
?>