<?php
  $fileFromForm = $_FILES["fileToUpload"];
  $uploadsDir = "../uploads";
  $targetDir = "../target";
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
    if($checkbox_setHR){
      $hr = inputParamsNvl("HR", 0);
      
      if(($hr < 30) || ($hr > 270)){
        echo "New value of HR the system should set is not specified or is out of the allowed range (from 30 to 270 bpm)";
        $hr = 0;
      }
    }
    
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
  
  if($checkbox_preserveJustXth)
    $tcx->preserveJustXth($xth);
  
  if($timestampsIntervalDefined)
    $tcx->modifyTrackPoints($timestampFrom, $timestampTo, $hr, $shiftLatitude, $shiftLongitude);
  
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
  
  
  function inputParamsNvl($name, $nvlValue){
    $post = $GLOBALS["_POST"];
    return (isset($post[$name])) ? $post[$name] : $nvlValue;
  }
  
  
  function inputCheckboxChecked($name){
    return (isset($GLOBALS["_POST"][$name]));
  }
  
?>