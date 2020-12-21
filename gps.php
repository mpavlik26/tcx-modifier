<?php
  $fileFromForm = $_FILES["fileToUpload"];
  $uploadsDir = "../uploads";
  $targetDir = "../target";
  $uploadFileName = $uploadsDir . "/" . $fileFromForm["name"];
  $targetFileName = $targetDir . "/" . $fileFromForm["name"];
  
  //INPUT PARAMETERS - begin:
  if($checkbox_preserveJustXth = isset($_POST["preserveJustXth"]))
    $xth = $_POST["xth"];
  
  if($checkbox_setHR = isset($_POST["setHR"])){
    $setHRTimestampFromAsText = $_POST["setHRTimestampFrom"];
    $setHRTimestampToAsText = $_POST["setHRTimestampTo"];
    $setHRTo = $_POST["setHRTo"];

    if(!isset($setHRTimestampFromAsText) || !isset($setHRTimestampFromAsText)){
      echo "'From' or 'To' timestamp for setting HR to a new value were not specified";
      $checkbox_setHR = false;
    }
    else{
      try{
        $setHRTimestampFrom = (new DateTime($setHRTimestampFromAsText))->getTimestamp();
        $setHRTimestampTo = (new DateTime($setHRTimestampToAsText))->getTimeStamp();
        
        if($setHRTimestampFrom > $setHRTimestampTo){
          echo "'From' timestamp (" . $setHRTimestampFromAsText . ") is greater than 'To' timestamp (" . $setHRTimestampToAsText . ")";
          $checkbox_setHR = false;
        }
        else{
          if(!isset($setHRTo) || ($setHRTo < 30) || ($setHRTo > 270)){
            echo "New value of HR the system should set is not specified or is out of the allowed range (from 30 to 270 bpm)";
            $checkbox_setHR = false;
          }
        }
      }
      catch(Error $e){
        echo "'From' or 'To' timestamps are specified incorrectly";
        $checkbox_setHR = false;
      }
    }
    
    if(!$checkbox_setHR)
      echo " and that's why the system will not set HR to a new value.";
  }
  
  echo "<br/>\n";
  //INPUT PARAMETERS - end
  
  if(!move_uploaded_file($fileFromForm["tmp_name"], $uploadFileName))
    exit("Unable to save uploaded file to uploads dir");

    
  $tcx = new TCXFile($uploadFileName);
  
  if($checkbox_preserveJustXth)
    $tcx->preserveJustXth($xth);
  
  if($checkbox_setHR)
    $tcx->setHR($setHRTo, $setHRTimestampFrom, $setHRTimestampTo);
  
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

    
    function setHR($hr, $timestampFrom, $timestampTo){
      $trackPoints = $this->getTrackPointsWithinTimestampsInterval($timestampFrom, $timestampTo);
      //print_r($trackPoints);
      
      foreach($trackPoints as $trackPoint){
        $trackPoint->setElementValue("n:HeartRateBpm/Value", $hr);
      }
      
      echo "HR (" . $hr . " bpm) was set to " . count($trackPoints) . " track points<br/>\n";
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
    
    function setElementValue($xpath, $value){
      $element = ($this->xpathEngine->query("n:HeartRateBpm/n:Value", $this->domElement))[0];
      $element->nodeValue = $value;
    }
  }
  
?>