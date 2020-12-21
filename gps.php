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
    $setHRTimestampFrom = $_POST["setHRTimestampFrom"];
    $setHRTimestampTo = $_POST["setHRTimestampTo"];
    $setHRTo = $_POST["setHRTo"];

    if(!isset($setHRTimestampFrom) || !isset($setHRTimestampTo)){
      echo "'From' or 'To' timestamp for setting HR to a new value were not specified";
      $checkbox_setHR = false;
    }
    else{
      if($setHRTimestampFrom > $setHRTimestampTo){
        echo "'From' timestamp (" . $setHRTimestampFrom . ") is greater than 'To' timestamp (" . $setHRTimestampTo . ")";
        $checkbox_setHR = false;
      }
      else{
        if(!isset($setHRTo) || ($setHRTo < 30) || ($setHRTo > 270)){
          echo "New value of HR the system should set is not specified or is out of the allowed range (from 30 to 270 bpm)";
          $checkbox_setHR = false;
        }
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
  
  $tcx->save($targetFileName);
  
  echo "Done";
  
  
  class TCXFile{
    public $xml;
    public $xpath;
    
    
    function __construct($fileName){
      $this->xml = new DomDocument();
      if(!$this->xml->load($fileName))
        exit("Unable to parse file '" . $fileName . "'");

      $this->xpath = new DOMXpath($this->xml);
      $this->xpath->registerNamespace("n", "http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2");
    }
    
    
    function preserveJustXth($xth){
      $tracks = $this->xpath->query("//n:Track");
      
      foreach($tracks as $track){
        $trackpoints = $this->xpath->query("n:Trackpoint", $track);
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
    
    
    function save($fileName){
      $this->xml->save($fileName); 
    }
  }
  
?>