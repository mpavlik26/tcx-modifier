<?php
  $fileFromForm = $_FILES["fileToUpload"];
  $uploadsDir = "uploads";
  $targetDir = "target";
  $uploadFileName = $uploadsDir . "/" . $fileFromForm["name"];
  $targetFileName = $targetDir . "/" . $fileFromForm["name"];
  
  $xth = $_POST["xth"];
  
  if(!move_uploaded_file($fileFromForm["tmp_name"], $uploadFileName))
    exit("Unable to save uploaded file to uploads dir");

  $xml = new DomDocument();
  if(!$xml->load($uploadFileName))
    exit("Unable to parse uploaded file");
  
  $backupFileName = $uploadFileName . ".bak";
  $xml->save($backupFileName);
    
  $xpath = new DOMXpath($xml);
  $xpath->registerNamespace("n", "http://www.garmin.com/xmlschemas/TrainingCenterDatabase/v2");
  
  $tracks = $xpath->query("//n:Track");
  
  foreach($tracks as $track){
    $trackpoints = $xpath->query("n:Trackpoint", $track);
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
  
  $xml->save($targetFileName);
  
  echo "Done";
  
?>