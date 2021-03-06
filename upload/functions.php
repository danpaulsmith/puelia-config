<?php

function query ($endpoint, $query) {
  $params = array(
    'http' => array(
      'method' => 'GET',
      'header' => 'Accept: application/sparql-results+json',
      'max_redirects' => 1,
      'ignore_errors' => true
    )
  );
  $ctx = stream_context_create($params);
  $query = array(
    'query' => $query,
    'output' => 'json'
  );
  $queryString = http_build_query($query);
  try {
    $fp = fopen($endpoint . '?' . $queryString, 'rb', false, $ctx);
    if (!$fp) {
      header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error");
      echo "<html><head><title>Error Accessing SPARQL Endpoint</title></head><body><p>Problem accessing $endpoint</p></body></html>";
      return array();
    } else {
      $response = stream_get_contents($fp);
      if ($response === false) {
        header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error");
        echo "<html><head><title>Error Getting Data</title></head><body><p>Problem reading data from $endpoint</p></body></html>";
        return array();
      } else {
        $results = json_decode($response);
        $bindings = $results->results->bindings;
        return $bindings;
      }
    }
  } catch (Exception $e) {
    header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal Server Error");
    echo "<html><head><title>Error Getting Data</title></head><body><p>Exception " . $e->getMessage() . ".</p></body></html>";
    return array();
  }
  
}

function organogramInfo ($dept, $isodate, $filenoext) {

  $graph = "http://organogram.data.gov.uk/data/$dept/$isodate/$filenoext";

  $endpoint = 'http://organogram.data.gov.uk:8080/openrdf-sesame/repositories/organogram';
  $gradeSparql = <<<GRADES
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX gov: <http://reference.data.gov.uk/def/central-government/>
PREFIX grade: <http://reference.data.gov.uk/def/civil-service-grade/>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX postStatus: <http://reference.data.gov.uk/def/civil-service-post-status/>

SELECT DISTINCT ?body ?bodyLabel ?grade
WHERE {
  ?body a gov:PublicBody ;
    foaf:page <$graph> ;
    rdfs:label ?bodyLabel .
  ?post gov:postIn ?body ;
    foaf:page <$graph> ;
    grade:grade ?grade .
  { ?post postStatus:postStatus postStatus:vacant . } 
  UNION 
  { ?post postStatus:postStatus postStatus:current . } 
}
ORDER BY ?body DESC(?grade)
GRADES;

  $topPostsSparql = <<<LOCATION
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX gov: <http://reference.data.gov.uk/def/central-government/>
PREFIX org: <http://www.w3.org/ns/org#>
PREFIX postStatus: <http://reference.data.gov.uk/def/civil-service-post-status/>
 
SELECT DISTINCT ?body ?post ?postLabel
WHERE { 
  ?post foaf:page <$graph> ; 
    gov:postIn ?body ;
    rdfs:label ?postLabel .
  { ?post postStatus:postStatus postStatus:vacant . } 
  UNION 
  { ?post postStatus:postStatus postStatus:current . } 
  { ?post a gov:CivilServicePost . }
  UNION
  { ?post a gov:MilitaryPost . }
  ?body a gov:PublicBody .
  OPTIONAL { ?post org:reportsTo ?manager } 
  FILTER (!BOUND(?manager))
}
ORDER BY ?post
LOCATION;

  $otherPostsSparql = <<<LOCATION
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX gov: <http://reference.data.gov.uk/def/central-government/>
PREFIX org: <http://www.w3.org/ns/org#>
PREFIX postStatus: <http://reference.data.gov.uk/def/civil-service-post-status/>
 
SELECT DISTINCT ?body ?post ?postLabel
WHERE { 
  ?post foaf:page <$graph> ; 
    gov:postIn ?body ;
    rdfs:label ?postLabel ;
    org:reportsTo [].
  { ?post postStatus:postStatus postStatus:vacant . } 
  UNION 
  { ?post postStatus:postStatus postStatus:current . }
  { ?post a gov:CivilServicePost . } 
  UNION 
  { ?post a gov:MilitaryPost . }
  ?body a gov:PublicBody .
}
ORDER BY ?post
LIMIT 3
LOCATION;

    $bodies = array();
    
    $bindings = query($endpoint, $gradeSparql);
    if ($bindings) {
      foreach($bindings as $binding) {
        $bodyUri = $binding->body->value;
        $bodyLabel = $binding->bodyLabel->value;
        $gradeUri = $binding->grade->value;

        $bodyUriParts = explode('/', substr($bodyUri, strpos($bodyUri, '/id/') + 4));
        $grade = substr($gradeUri, strpos($gradeUri, '-grade/') + 7);
        if ($bodies[$bodyUri]) {
          $bodies[$bodyUri]['grades'][$grade] = true;
        } else {
          $bodies[$bodyUri] = array(
            'type' => $bodyUriParts[0] == 'department' ? 'dept' : 'pubbod',
            'id' => $bodyUriParts[1],
            'label' => $bodyLabel,
            'grades' => array($grade => true)
          );
        }
      }
    }
    
    $bindings = query($endpoint, $topPostsSparql);
    if ($bindings) {
      foreach($bindings as $binding) {
        $bodyUri = $binding->body->value;
        $postUri = $binding->post->value;
        $postLabel = $binding->postLabel->value;

        $postUriParts = explode('/', substr($postUri, strpos($postUri, '/id/') + 4));
        if ($bodies[$bodyUri]) {
          if (!$bodies[$bodyUri]['posts']) {
            $bodies[$bodyUri]['posts'] = array();
          }
          $bodies[$bodyUri]['posts'][] = array(
            'id' => $postUriParts[3],
            'label' => $postLabel
          );
        }
      }
    }
    if (count($bindings) < 3) {
      $bindings = query($endpoint, $otherPostsSparql);
      if ($bindings) {
        foreach($bindings as $binding) {
          $bodyUri = $binding->body->value;
          $postUri = $binding->post->value;
          $postLabel = $binding->postLabel->value;

          $postUriParts = explode('/', substr($postUri, strpos($postUri, '/id/') + 4));
          if ($bodies[$bodyUri]) {
            if (!$bodies[$bodyUri]['posts']) {
              $bodies[$bodyUri]['posts'] = array();
            }
            $bodies[$bodyUri]['posts'][] = array(
              'id' => $postUriParts[3],
              'label' => $postLabel
            );
          }
        }
      }
    }
    
    return $bodies;
}

function createSeniorCSV($filename) {

    $excel = new Spreadsheet_Excel_Reader();
    $excel->setOutputEncoding('CP1251');
    $excel->read($filename);

    $x=1;
    $valid = true;
    $sep = ",";

    ob_start();

    while($x<=$excel->sheets[4]['numRows'] && isset($excel->sheets[4]['cells'][$x][1]) && $excel->sheets[4]['cells'][$x][1] != '') {
       $y=1;
       $row="";

       while($y<=19) {
         if ($y == 16) {
           // strip salary information from SCS spreadsheet
           $cell = '';
         } else {
           $cell = isset($excel->sheets[4]['cells'][$x][$y]) ? $excel->sheets[4]['cells'][$x][$y] : '';
           $cell = preg_replace('/\s+/', ' ', trim($cell));
           // strip leading $ signs, seem to come from formatting numbers as currency
           $cell = preg_replace('/^\$/', '', $cell);
           $cell = str_replace('’', '\'', $cell);
           $cell = str_replace('"', '\'', $cell);
         }
         $row.=($row=="")?"\"".$cell."\"":"".$sep."\"".$cell."\"";
         $y++;
       } 

       if ($x > 1 && strval($excel->sheets[4]['cells'][$x][17]) != 'Military' && strval($excel->sheets[4]['cells'][$x][17]) != 'Other' && strval($excel->sheets[4]['cells'][$x][19]) != '1') {
         $valid = false;
       }

      echo $row."\n"; 
      
      $x++;
    }

    $extIndex = strrpos($filename, ".xls");
    $saveAs = substr($filename, 0, $extIndex) . '-senior-data.csv';

    $fp = fopen($saveAs,'w');
    fwrite($fp,ob_get_contents());
    fclose($fp);
    ob_end_clean();

    if (!$valid) {
      return 'Some of the rows in the (final data) senior-staff worksheet are invalid.';
    } else if ($x <= 2) {
      return 'There is no data in the (final data) senior-staff worksheet.';
    }

    return false;
}

function createJuniorCSV($filename) {

    $excel = new Spreadsheet_Excel_Reader();
    $excel->setOutputEncoding('CP1251');
    $excel->read($filename);

    $x=1;
    $valid = true;
    $sep = ",";

    ob_start();

    while($x<=$excel->sheets[6]['numRows'] && isset($excel->sheets[6]['cells'][$x][1]) && $excel->sheets[6]['cells'][$x][1] != '') {
       $y=1;
       $row="";

       while($y<=10) {
           $cell = isset($excel->sheets[6]['cells'][$x][$y]) ? $excel->sheets[6]['cells'][$x][$y] : '';
           $cell = preg_replace('/\s+/', ' ', trim($cell));
           // strip leading $ signs, seem to come from formatting numbers as currency
           $cell = preg_replace('/^\$/', '', $cell);
           $cell = str_replace('’', '\'', $cell);
           $cell = str_replace('"', '\'', $cell);
           $row.=($row=="")?"\"".$cell."\"":"".$sep."\"".$cell."\"";
           $y++;
       } 

      echo $row."\n";
      /*
      if ($excel->sheets[6]['cells'][$x][11] != '1') {
        $valid = false;
      }
      */
      $x++;

    }

    $extIndex = strrpos($filename, ".xls");
    $saveAs = substr($filename, 0, $extIndex) . '-junior-data.csv';

    $fp = fopen($saveAs,'w');
    fwrite($fp,ob_get_contents());
    fclose($fp);
    ob_end_clean();

    if (!$valid) {
      return 'Some of the rows in the (final data) junior-staff worksheet are invalid.';
    } else if ($x <= 2) {
      return 'There is no data in the (final data) junior-staff worksheet.';
    }

    return false;
}


function validEmail($email) {
   $isValid = true;
   $atIndex = strrpos($email, "@");
   if (is_bool($atIndex) && !$atIndex)
   {
      $isValid = false;
   } else {
      $domain = substr($email, $atIndex+1);
      $local = substr($email, 0, $atIndex);
      $localLen = strlen($local);
      $domainLen = strlen($domain);
      if ($localLen < 1 || $localLen > 64)
      {
         // local part length exceeded
         $isValid = false;
      }
      else if ($domainLen < 1 || $domainLen > 255)
      {
         // domain part length exceeded
         $isValid = false;
      }
      else if ($local[0] == '.' || $local[$localLen-1] == '.')
      {
         // local part starts or ends with '.'
         $isValid = false;
      }
      else if (preg_match('/\\.\\./', $local))
      {
         // local part has two consecutive dots
         $isValid = false;
      }
      else if (!preg_match('/^[A-Za-z0-9\\-\\.]+$/', $domain))
      {
         // character not valid in domain part
         $isValid = false;
      }
      else if (preg_match('/\\.\\./', $domain))
      {
         // domain part has two consecutive dots
         $isValid = false;
      }
      else if (!preg_match('/^(\\\\.|[A-Za-z0-9!#%&`_=\\/$\'*+?^{}|~.-])+$/', str_replace("\\\\","",$local)))
      {
         // character not valid in local part unless 
         // local part is quoted
         if (!preg_match('/^"(\\\\"|[^"])+"$/', str_replace("\\\\","",$local)))
         {
            $isValid = false;
         }
      }
   }
   return $isValid;
}

function departmentFromEmail($email) {
  $domain = substr($email, strrpos($email, "@") + 1);
  return substr($domain,0,strpos($domain,"."));
}

function isoFormatDate($date) {
  $parts = explode('/', $date);
  return "{$parts[2]}-{$parts[1]}-{$parts[0]}";
}

function make_dir_for_file($filesystemLoc) {
  $filesystemDir = dirname($filesystemLoc);
  $dirExists = file_exists($filesystemDir);
  if (!$dirExists) {
    $dirExists = mkdir($filesystemDir, 0755, true);
  }
  if (!is_writable($filesystemDir)) {
    $dirExists = chmod($filesystemDir, 755);
  }
  return $dirExists;
}


function file_upload_error_message($error_code) {
  switch ($error_code) {
    case UPLOAD_ERR_INI_SIZE:
      return 'The uploaded file exceeds the upload_max_filesize directive in php.ini';
    case UPLOAD_ERR_FORM_SIZE:
      return 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form';
    case UPLOAD_ERR_PARTIAL:
      return 'The uploaded file was only partially uploaded';
    case UPLOAD_ERR_NO_FILE:
      return 'No file was uploaded';
    case UPLOAD_ERR_NO_TMP_DIR:
      return 'Missing a temporary folder';
    case UPLOAD_ERR_CANT_WRITE:
      return 'Failed to write file to disk';
    case UPLOAD_ERR_EXTENSION:
      return 'File upload stopped by extension';
    default:
      return 'Unknown upload error';
  }
}

function writeTransformation($dept, $date, $filename, $email, $xlwrapMappingsDir) {

  // set department and file extension
  $deptName = $dept;
  $ext="xls";

  // format the date  
  $parts = explode('-', $date);
  $dateSlash = "{$parts[2]}/{$parts[1]}/{$parts[0]}";

  // remove the .xls extension from filename
  $extIndex = strrpos($filename, ".xls");
  $path = substr($filename, 0, $extIndex);
  $saveAs = "data/$dept/$date/$path-mapping.trig";
  $xlwrapCopy = "$xlwrapMappingsDir/$dept-$date-$path.trig";

  $fileURL = "http://organogram.data.gov.uk/data/$dept/$date/$path";

// using heredoc syntax

$str = <<<TRANSFORMATION

@prefix rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#> .
@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .
@prefix xsd: <http://www.w3.org/2001/XMLSchema#> .

@prefix skos: <http://www.w3.org/2004/02/skos/core#> .
@prefix foaf: <http://xmlns.com/foaf/0.1/> .
@prefix vcard: <http://www.w3.org/2006/vcard/> .
@prefix dct: <http://purl.org/dc/terms/> .
@prefix void: <http://rdfs.org/ns/void#> .
@prefix time: <http://www.w3.org/2006/time#> .
@prefix qb: <http://purl.org/linked-data/cube#> .
@prefix sdmxa: <http://purl.org/linked-data/sdmx/2009/attribute#> .
@prefix sdmxc: <http://purl.org/linked-data/sdmx/2009/code#> .
@prefix org: <http://www.w3.org/ns/org#> .

@prefix copmv: <http://purl.org/net/opmv/types/common#> .
@prefix opmv: <http://purl.org/net/opmv/ns#> .

@prefix dgu: <http://reference.data.gov.uk/def/reference/> .
@prefix gov: <http://reference.data.gov.uk/def/central-government/> .
@prefix organogram: <http://reference.data.gov.uk/def/organogram/> .
@prefix grade: <http://reference.data.gov.uk/def/civil-service-grade/> .
@prefix payband: <http://reference.data.gov.uk/def/civil-service-payband/> .
@prefix postStatus: <http://reference.data.gov.uk/def/civil-service-post-status/> .
@prefix profession: <http://reference.data.gov.uk/def/civil-service-profession/> .

@prefix xl: <http://purl.org/NET/xlwrap#> .
@prefix debug: <http://debug.example.org/> .
@prefix :   <$fileURL/mapping> .

# mapping
{ [] a xl:Mapping ;
  xl:offline "false"^^xsd:boolean ;
  
  # datasets
  xl:template [
    xl:fileName "$fileURL-senior-data.csv" ;
#   xl:sheetNumber "1" ;
    xl:templateGraph :datasets ;
    xl:transform [
      a xl:RowShift ;
      xl:breakCondition "ROW(A2) > 2" ;
    ]
  ] ;
  
  # senior staff posts
  xl:template [
    xl:fileName "$fileURL-senior-data.csv" ;
#   xl:sheetNumber "4" ;
    xl:templateGraph :seniorPosts ;
    xl:transform [ 
      a xl:RowShift ;
      xl:breakCondition "EMPTY(A2)" ;
    ]
  ] ;

  # junior staff data
  xl:template [
    xl:fileName "$fileURL-junior-data.csv" ;
#   xl:sheetNumber "6" ;
    xl:templateGraph :juniorStaff ;
    xl:transform [ 
      a xl:RowShift ;
      xl:breakCondition "EMPTY(A2)" ;
    ]
  ] ;
  .
}

:seniorPosts {
  
  [ xl:uri "IF(STRING(A2) != '0', NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/post/' & A2)"^^xl:Expr ] 
    a [ xl:uri "IF(UCASE(C2) == 'SCS4' || UCASE(C2) == 'SCS3' || UCASE(C2) == 'SCS2' || UCASE(C2) == 'SCS1' || UCASE(C2) == 'SCS1A', 'http://reference.data.gov.uk/def/central-government/CivilServicePost', 'http://reference.data.gov.uk/def/central-government/MilitaryPost')"^^xl:Expr ] ;
    a [ xl:uri "IF(UCASE(C2) == 'SCS4' || UCASE(C2) == 'SCS3' || UCASE(C2) == 'SCS2' || UCASE(C2) == 'SCS1' || UCASE(C2) == 'SCS1A', 'http://reference.data.gov.uk/def/central-government/SeniorCivilServicePost', 'http://reference.data.gov.uk/def/central-government/SeniorMilitaryPost')"^^xl:Expr ] ;
    rdfs:label "D2"^^xl:Expr ;
    rdfs:comment "E2"^^xl:Expr ;
    skos:notation "STRING(A2)"^^xl:Expr ;
    gov:postIn [ 
      # department or public body
      xl:uri "IF(STRING(A2) != '0', NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf'))"^^xl:Expr ;
      a gov:PublicBody, org:Organization ;
      a [ xl:uri "IF(F2 == G2, 'http://reference.data.gov.uk/def/central-government/Department')"^^xl:Expr ] ;
      rdfs:label "G2"^^xl:Expr ;
      dgu:uriSet [ xl:uri "'http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body')"^^xl:Expr ] ;
      org:hasUnit [ 
        xl:uri "IF(!(SUBSTRING(H2, 50) != ''), NAME2URI(NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/unit/', H2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/unit.rdf'), NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/unit/' & SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(LCASE(H2), '-', ''), \"'\", ''), '  ', ' '), ' ', '-'))"^^xl:Expr ; 
      ] ;
      gov:parentDepartment [
        xl:uri "IF(F2 != G2 && STRING(A2) != '0', NAME2URI('http://reference.data.gov.uk/id/department/', F2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/department.rdf'))"^^xl:Expr ;
        a gov:Department, gov:PublicBody, org:Organization ;
        rdfs:label "F2"^^xl:Expr ;
        dgu:uriSet <http://reference.data.gov.uk/id/department> ;
        foaf:page <$fileURL> ;
      ] ;
      foaf:page <$fileURL> ;
    ], [ 
      # unit
      xl:uri "IF(!(SUBSTRING(H2, 50) != ''), NAME2URI(NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/unit/', H2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/unit.rdf'), NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/unit/' & SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(LCASE(H2), '-', ''), \"'\", ''), '  ', ' '), ' ', '-'))"^^xl:Expr ; 
      a org:OrganizationalUnit ;
      rdfs:label "H2"^^xl:Expr ;
      org:unitOf [ xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf')"^^xl:Expr ] ;
      gov:hasPost [ xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/post/' & A2"^^xl:Expr ] ;
      foaf:page <$fileURL> ;
    ] ;
    grade:grade [ 
      xl:uri "IF(UCASE(C2) == 'SCS4' || UCASE(C2) == 'SCS3' || UCASE(C2) == 'SCS2' || UCASE(C2) == 'SCS1' || UCASE(C2) == 'SCS1A', 'http://reference.data.gov.uk/def/civil-service-grade/' & UCASE(C2), 'http://reference.data.gov.uk/def/military-grade/' & UCASE(C2))"^^xl:Expr ;
      rdfs:label "UCASE(C2)"^^xl:Expr ;
    ] ;
    postStatus:postStatus [ xl:uri "'http://reference.data.gov.uk/def/civil-service-post-status/' & IF(LCASE(B2) == 'vacant', 'vacant', IF(LCASE(B2) == 'eliminated', 'eliminated', 'current'))"^^xl:Expr ] ;
    org:reportsTo [ xl:uri "IF(UCASE(STRING(K2)) != 'XX', NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/post/' & K2)"^^xl:Expr ; ] ;
    gov:heldBy [ 
      # person
      xl:uri "IF(LCASE(B2) != 'vacant' && LCASE(B2) != 'eliminated' && STRING(A2) != '0', '$fileURL#person' & ROW(A2))"^^xl:Expr ;
      a foaf:Person ;
      foaf:name "IF(UCASE(B2) != 'N/D' && UCASE(B2) != 'N/A', B2)"^^xl:Expr ;
      foaf:phone [
        xl:uri "IF(UCASE(I2) != 'N/D' && UCASE(I2) != 'N/A', 'tel:+44.' & SUBSTRING(SUBSTITUTE(I2, ' ', '.'), 1))"^^xl:Expr ;
        a vcard:Tel ;
        rdfs:label "I2"^^xl:Expr ;
        foaf:page <$fileURL> ;
      ] ;
      foaf:mbox [
        xl:uri "IF(UCASE(J2) != 'N/D' && UCASE(J2) != 'N/A', 'mailto:' & J2)"^^xl:Expr ;
        a vcard:Email ;
        rdfs:label "J2"^^xl:Expr ;
        foaf:page <$fileURL> ;
      ] ;
      gov:holdsPost [ xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/post/' & A2"^^xl:Expr ] ;
      gov:tenure [ 
        # tenure
        xl:uri "IF(STRING(A2) != '0', '$fileURL#tenure' & ROW(A2))"^^xl:Expr ;
        a gov:Tenure , org:Membership ;
        ## NOTE: derive label as "{person name} as {job title}"
        rdfs:label "B2 & ' as ' & D2"^^xl:Expr ;
        gov:postholder [ xl:uri "'$fileURL#person' & ROW(A2)"^^xl:Expr ] ;
        gov:post [ xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/post/' & A2"^^xl:Expr ] ;
        gov:salary "IF(!EMPTY(P2), IF(UCASE(STRING(P2)) != 'N/D' && UCASE(STRING(P2)) != 'N/A', LONG(P2)))"^^xl:Expr ;
        gov:fullTimeEquivalent "DOUBLE(M2)"^^xl:Expr ;
        foaf:page <$fileURL> ;
      ] ;
      profession:profession [
        # profession
        xl:uri "IF(Q2 != '' && LCASE(Q2) != 'military' && LCASE(Q2) != 'other', NAME2URI('http://reference.data.gov.uk/def/civil-service-profession/', Q2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/profession.rdf'))"^^xl:Expr ;
        skos:prefLabel "Q2"^^xl:Expr ;
      ] ;
      foaf:page <$fileURL> ;
    ] ;
    gov:salaryRange [ 
      xl:uri "IF(UCASE(STRING(N2)) != 'N/D' && UCASE(STRING(N2)) != 'N/A' && LCASE(B2) != 'vacant' && LCASE(B2) != 'eliminated' && STRING(A2) != '0', 'http://reference.data.gov.uk/id/salary-range/' & N2 & '-' & O2)"^^xl:Expr ;
      a gov:SalaryRange ;
      ## NOTE: derive from salaries
      rdfs:label "'£' & N2 & ' - £' & O2"^^xl:Expr ;
      gov:lowerBound "LONG(N2)"^^xl:Expr ;
      gov:upperBound "LONG(O2)"^^xl:Expr ;
      dgu:uriSet <http://reference.data.gov.uk/id/salary-range> ;
      foaf:page <$fileURL> ;
    ] ;
    skos:note "IF(R2 != '', R2)"^^xl:Expr ;
    foaf:page <$fileURL> ;
    .
  
  # people without posts
  [ xl:uri "IF(LCASE(B2) != 'vacant' && LCASE(B2) != 'eliminated' && STRING(A2) == '0', '$fileURL#person' & ROW(A2))"^^xl:Expr ]
    a foaf:Person ;
    grade:grade [ 
      xl:uri "IF(UCASE(C2) == 'SCS4' || UCASE(C2) == 'SCS3' || UCASE(C2) == 'SCS2' || UCASE(C2) == 'SCS1' || UCASE(C2) == 'SCS1A', 'http://reference.data.gov.uk/def/civil-service-grade/' & UCASE(C2), 'http://reference.data.gov.uk/def/military-grade/' & UCASE(C2))"^^xl:Expr ;
      rdfs:label "UCASE(C2)"^^xl:Expr ;
    ] ;
    org:reportsTo [ xl:uri "IF(UCASE(STRING(K2)) != 'XX', NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/post/' & K2)"^^xl:Expr ] ;
    org:memberOf [ xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf')"^^xl:Expr ] ;
    org:hasMembership [ 
      xl:uri "IF(LCASE(B2) != 'vacant' && LCASE(B2) != 'eliminated' && STRING(A2) == '0', '$fileURL#tenure' & ROW(A2))"^^xl:Expr ;
      a org:Membership ;
      ## NOTE: derive label as "{person name} in {organisation}"
      rdfs:label "B2 & ' in ' & G2"^^xl:Expr ;
      org:member [ xl:uri "'$fileURL#person' & ROW(A2)"^^xl:Expr ] ;
      org:organization [ xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf')"^^xl:Expr ] ;
      gov:salaryRange [
        xl:uri "IF(UCASE(STRING(N2)) != 'N/D' && UCASE(STRING(N2)) != 'N/A' && LCASE(B2) != 'vacant' && LCASE(B2) != 'eliminated' && STRING(A2) == '0', 'http://reference.data.gov.uk/id/salary-range/' & N2 & '-' & O2)"^^xl:Expr ;
        a gov:SalaryRange ;
        ## NOTE: derive from salaries
        rdfs:label "'£' & N2 & ' - £' & O2"^^xl:Expr ;
        gov:lowerBound "LONG(N2)"^^xl:Expr ;
        gov:upperBound "LONG(O2)"^^xl:Expr ;
        dgu:uriSet <http://reference.data.gov.uk/id/salary-range> ;
        foaf:page <$fileURL> ;
      ] ;
      gov:fullTimeEquivalent "DOUBLE(M2)"^^xl:Expr ;
      foaf:page <$fileURL> ;
    ] ;
    foaf:page <$fileURL> ;
    .
  
  # salary cost of reports observation
  [ xl:uri "IF(LCASE(B2) != 'eliminated', '$fileURL#salaryCostOfReports' & ROW(A2))"^^xl:Expr ]
    a qb:Observation ;
    rdfs:label "D2 & ' Salary Cost of Reports on $dateSlash'"^^xl:Expr ;
    qb:dataSet <$fileURL#salaryCostOfReports> ;
    organogram:date <http://reference.data.gov.uk/id/day/$date> ;
    organogram:post [ xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/post/' & A2"^^xl:Expr ] ;
    organogram:salaryCostOfReports "IF (UCASE(STRING(L2)) != 'N/D', LONG(L2))"^^xl:Expr ;
    sdmxa:obsStatus [ xl:uri "IF(UCASE(STRING(L2)) == 'N/D', 'http://purl.org/linked-data/sdmx/2009/code#obsStatus-M')"^^xl:Expr ] ;
    .

  # totalPay observation
  [ xl:uri "IF((UCASE(C2) == 'SCS4' || UCASE(C2) == 'SCS3' || UCASE(C2) == 'SCS2') && LCASE(B2) != 'vacant' && LCASE(B2) != 'eliminated', '$fileURL#totalPay' & ROW(A2))"^^xl:Expr ]
    a qb:Observation ;
    rdfs:label "B2 & ' as ' & D2 & ' Total Pay on $dateSlash'"^^xl:Expr ;
    qb:dataSet <$fileURL#totalPay> ;
    organogram:date <http://reference.data.gov.uk/id/day/$date> ;
    organogram:tenure [ xl:uri "'$fileURL#tenure' & ROW(A2)"^^xl:Expr ; ] ;
    organogram:totalPay "IF(!EMPTY(P2), IF(UCASE(STRING(P2)) != 'N/D' && UCASE(STRING(P2)) != 'N/A', LONG(P2)))"^^xl:Expr ;
    sdmxa:obsStatus [ xl:uri "IF(!EMPTY(P2), IF(UCASE(STRING(P2)) == 'N/D', 'http://purl.org/linked-data/sdmx/2009/code#obsStatus-M'))"^^xl:Expr ] ;
    .

  # non-disclosed names
  [ xl:uri "IF(UCASE(B2) == 'N/D' && (UCASE(C2) == 'SCS4' || UCASE(C2) == 'SCS3' || UCASE(C2) == 'SCS2'), '$fileURL#nameDisclosure' & ROW(A2))"^^xl:Expr ]
    a gov:NonDisclosure , rdf:Statement ;
    rdfs:label "'Non-Disclosure of name of ' & D2"^^xl:Expr ;
    rdf:subject [ xl:uri "'$fileURL#person' & ROW(A2)"^^xl:Expr ] ;
    rdf:predicate foaf:name ;
    foaf:page <$fileURL> ;
    .

  # non-disclosed total pay
  [ xl:uri "IF(!EMPTY(P2), IF(UCASE(STRING(P2)) == 'N/D' && (UCASE(C2) == 'SCS4' || UCASE(C2) == 'SCS3' || UCASE(C2) == 'SCS2'), '$fileURL#totalPayDisclosure' & ROW(A2)))"^^xl:Expr ]
    a gov:NonDisclosure , rdf:Statement ;
    rdfs:label "'Non-Disclosure of total pay of ' & B2 & ' as ' & D2"^^xl:Expr ;
    rdf:subject [ xl:uri "'$fileURL#tenure' & ROW(A2)"^^xl:Expr ] ;
    rdf:predicate gov:salary ;
    foaf:page <$fileURL> ;
    .

}

:datasets {

  ## NOTE: static across dataset
  <$fileURL>
    a opmv:Artifact, void:Dataset ;
    dct:title "G2 & ' Organogram at $dateSlash Dataset'"^^xl:Expr ;
    dct:license <http://reference.data.gov.uk/id/open-government-licence> ;
    dct:source <$fileURL.$ext> ;
    dct:contributor [
      a foaf:Person ;
      rdf:label "$email" ;
      foaf:mbox <mailto:$email> ;
      foaf:page <$fileURL> ;
    ] ;
    dct:temporal <http://reference.data.gov.uk/id/day/$date> ;
    void:exampleResource
      <http://reference.data.gov.uk/id/department/co> ,
      <$fileURL#person2> ,
      <$fileURL#tenure2> ;
    void:vocabulary 
      <http://www.w3.org/2000/01/rdf-schema> ,
      <http://www.w3.org/2004/02/skos/core> , 
      <http://xmlns.com/foaf/0.1/> ,
      <http://www.w3.org/ns/org> , 
      <http://reference.data.gov.uk/def/central-government/> ;
    void:subset
      <$fileURL#salaryCostOfReports> ,
      <$fileURL#totalPay> ,
      <$fileURL#juniorPosts> ,
      [
        # junior grade concept scheme
        xl:uri "SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/grade', '/id/', '/def/')"^^xl:Expr
      ] ,
      [
        # junior grade concept scheme
        xl:uri "SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (F2 == G2, 'department', 'public-body') & '/', G2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (F2 == G2, 'department', 'public-body') & '.rdf') & '/payband', '/id/', '/def/')"^^xl:Expr
      ] ;
    .

  <mailto:$email>
    a vcard:Email ;
    rdfs:label "$email" ;
    foaf:page <$fileURL> ;
    .

  ## NOTE: static across dataset
  <$fileURL#salaryCostOfReports>
    a void:Dataset , qb:DataSet ;
    dct:title "G2 & ' Salary Cost of Reports on $dateSlash Dataset'"^^xl:Expr ;
    dct:license <http://reference.data.gov.uk/id/open-government-licence> ;
    dct:source <$fileURL.$ext> ;
    dct:temporal <http://reference.data.gov.uk/id/day/$date> ;
    qb:structure <http://reference.data.gov.uk/def/organogram/salary-costs-of-reports> ;
    void:exampleResource
      <$fileURL#salaryCostOfReports1> ;
    void:vocabulary
      <http://www.w3.org/2000/01/rdf-schema> ,
      <http://purl.org/linked-data/cube> ,
      <http://reference.data.gov.uk/def/organogram/> .

  ## NOTE: static across dataset
  <$fileURL#totalPay>
    a void:Dataset , qb:DataSet ;
    dct:title "G2 & ' Total Pay on $dateSlash Dataset'"^^xl:Expr ;
    dct:license <http://reference.data.gov.uk/id/open-government-licence> ;
    dct:source <$fileURL.$ext> ;
    dct:temporal <http://reference.data.gov.uk/id/day/$date> ;
    qb:structure <http://reference.data.gov.uk/def/organogram/total-pay> ;
    void:exampleResource
      <$fileURL#totalPay1> ;
    void:vocabulary
      <http://www.w3.org/2000/01/rdf-schema> ,
      <http://purl.org/linked-data/cube> ,
      <http://reference.data.gov.uk/def/organogram/> .

  ## NOTE: static across dataset
  <$fileURL#juniorPosts>
    a void:Dataset , qb:DataSet ;
    dct:title "G2 & ' Junior Post FTEs at $dateSlash Dataset'"^^xl:Expr ;
    dct:license <http://reference.data.gov.uk/id/open-government-licence> ;
    dct:source <$fileURL.$ext> ;
    dct:temporal <http://reference.data.gov.uk/id/day/$date> ;
    qb:structure <http://reference.data.gov.uk/def/organogram/junior-posts> ;
    void:exampleResource
      <$fileURL#juniorPosts1> ;
    void:vocabulary
      <http://www.w3.org/2000/01/rdf-schema> ,
      <http://purl.org/linked-data/cube> ,
      <http://reference.data.gov.uk/def/organogram/> .

  <http://reference.data.gov.uk/id/day/$date>
    a <http://reference.data.gov.uk/def/intervals/CalendarDay> ;
    rdfs:label "$date" .
    
  <http://reference.data.gov.uk/id/department>
    a dgu:UriSet , void:Dataset ;
    rdfs:label "Government Departments" ;
    void:class gov:Department ;
    void:exampleResource <http://reference.data.gov.uk/id/department/co> .
  
}

:juniorStaff {
  
  [ xl:uri "'$fileURL#juniorPosts' & ROW(A2)"^^xl:Expr ]
    a qb:Observation ;
    ## NOTE: construct from "{grade} {job title} ({profession}) in {unit} reporting to post {reports to} at $dateSlash"
    rdfs:label "E2 & ' ' & H2 & ' (' & J2 & ') in ' & UCASE(C2) & ' reporting to post ' & D2 & ' FTE at $dateSlash'"^^xl:Expr ;
    qb:dataSet <$fileURL#juniorPosts> ;
    organogram:date <http://reference.data.gov.uk/id/day/$date> ;
    organogram:unit [ 
      xl:uri "IF(!(SUBSTRING(C2, 50) != ''), NAME2URI(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/unit/', C2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/unit.rdf'), NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/unit/' & SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(LCASE(C2), '-', ''), \"'\", ''), '  ', ' '), ' ', '-'))"^^xl:Expr ; 
      a org:OrganizationalUnit ;
      rdfs:label "C2"^^xl:Expr ;
      org:unitOf [ 
        xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf')"^^xl:Expr ;
        a gov:PublicBody, org:Organization ;
        a [ xl:uri "IF(A2 == B2, 'http://reference.data.gov.uk/def/central-government/Department')"^^xl:Expr ] ;
        rdfs:label "B2"^^xl:Expr ;
        dgu:uriSet [ xl:uri "'http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body')"^^xl:Expr ] ;
        org:hasUnit [ 
          xl:uri "IF(!(SUBSTRING(C2, 50) != ''), NAME2URI(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/unit/', C2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/unit.rdf'), NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/unit/' & SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(SUBSTITUTE(LCASE(C2), '-', ''), \"'\", ''), '  ', ' '), ' ', '-'))"^^xl:Expr ; 
        ] ;
        gov:parentDepartment [
          xl:uri "IF(A2 != B2, NAME2URI('http://reference.data.gov.uk/id/department/', A2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/department.rdf'))"^^xl:Expr ;
          a gov:Department, gov:PublicBody, org:Organization ;
          rdfs:label "A2"^^xl:Expr ;
          dgu:uriSet <http://reference.data.gov.uk/id/department> ;
          foaf:page <$fileURL> ;
        ] ;
        foaf:page <$fileURL> ;
      ] ;
      foaf:page <$fileURL> ;
    ] ;
    organogram:reportingTo [ xl:uri "NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/post/' & D2"^^xl:Expr ] ;
    organogram:grade [
      xl:uri "NAME2URI(SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/grade/', '/id/', '/def/'), E2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/grade.rdf')"^^xl:Expr ;
      a grade:Grade ;
      skos:prefLabel "E2"^^xl:Expr ;
      skos:topConceptOf [
        # grade concept scheme
        xl:uri "SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/grade', '/id/', '/def/')"^^xl:Expr ;
        a skos:ConceptScheme , void:Dataset ;
        skos:prefLabel "B2 & ' Junior Civil Service Grades'"^^xl:Expr ;
        skos:hasTopConcept [
          # inverse pointer to grade
          xl:uri "NAME2URI(SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/grade/', '/id/', '/def/'), E2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/grade.rdf')"^^xl:Expr ;
        ] ;
      ] ;
      payband:payBand [
        xl:uri "NAME2URI(SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/payband/', '/id/', '/def/'), E2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/payband.rdf')"^^xl:Expr ;
        a payband:PayBand ;
        skos:prefLabel "E2 & ' Payband'"^^xl:Expr ;
        skos:topConceptOf [
          # payband concept scheme
          xl:uri "SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/payband', '/id/', '/def/')"^^xl:Expr ;
          a skos:ConceptScheme , void:Dataset ;
          skos:prefLabel "B2 & ' Junior Civil Service Grades'"^^xl:Expr ;
          skos:hasTopConcept [
            # inverse pointer to grade
            xl:uri "NAME2URI(SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/payband/', '/id/', '/def/'), E2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/payband.rdf')"^^xl:Expr ;
          ] ;
        ] ;
        gov:salaryRange [ 
          xl:uri "IF(!EMPTY(F2), IF(UCASE(STRING(F2)) != 'N/D','http://reference.data.gov.uk/id/salary-range/' & F2 & '-' & G2))"^^xl:Expr ;
          a gov:SalaryRange ;
          ## NOTE: derive from salaries
          rdfs:label "'£' & F2 & ' - £' & G2"^^xl:Expr ;
          gov:lowerBound "LONG(F2)"^^xl:Expr ;
          gov:upperBound "LONG(G2)"^^xl:Expr ;
          dgu:uriSet <http://reference.data.gov.uk/id/salary-range> ;
          foaf:page <$fileURL> ;
        ] ;
      ] ;
    ] ;
    ## Note: assuming here that the job is a standard one
    organogram:job [
      xl:uri "NAME2URI('http://reference.data.gov.uk/def/civil-service-job/', H2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/job.rdf')"^^xl:Expr ;
      skos:prefLabel "H2"^^xl:Expr ;
    ] ;
    organogram:profession [
      xl:uri "IF(LCASE(J2) != 'other', NAME2URI('http://reference.data.gov.uk/def/civil-service-profession/', J2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/profession.rdf'))"^^xl:Expr ;
      skos:prefLabel "J2"^^xl:Expr ;
    ] ;
    organogram:fullTimeEquivalent "DOUBLE(I2)"^^xl:Expr ;
    .
  

  # non-disclosed payband salary range
  [ xl:uri "IF(EMPTY(F2), '$fileURL#salaryRangeDisclosure' & ROW(A2), IF(UCASE(STRING(F2)) == 'N/D', '$fileURL#salaryRangeDisclosure' & ROW(A2)))"^^xl:Expr ]
    a gov:NonDisclosure , rdf:Statement ;
    rdfs:label "'Non-Disclosure of salary range for ' & E2 & ' Payband'"^^xl:Expr ;
    rdf:subject [ xl:uri "NAME2URI(SUBSTITUTE(NAME2URI('http://reference.data.gov.uk/id/' & IF (A2 == B2, 'department', 'public-body') & '/', B2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/' & IF (A2 == B2, 'department', 'public-body') & '.rdf') & '/payband/', '/id/', '/def/'), E2, 'mappings/reconcile/reference/diacritics.txt', 'mappings/reconcile/reference/payband.rdf')"^^xl:Expr ] ;
    rdf:predicate gov:salaryRange ;
    foaf:page <$fileURL> ;
    .
}

TRANSFORMATION;

$stream = fopen($saveAs, 'w');
fwrite($stream, $str);
fclose($stream);

$stream = fopen($xlwrapCopy, 'w');
fwrite($stream, $str);
fclose($stream);

}


function createRDF ($dept, $date, $filename) {
  
  $fileLocation = "data/$dept/$date/$filename.rdf";

  if (!file_exists($fileLocation)) {
    $graph = "http://organogram.data.gov.uk/data/$dept/$date/$filename";
    $endpoint = 'http://organogram.data.gov.uk:8900/sparql';

    $check = <<<EOD
ASK {
  <$graph> ?p ?o .
}
EOD;

    $sparql = <<<EOD
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX xsd: <http://www.w3.org/2001/XMLSchema#>
PREFIX skos: <http://www.w3.org/2004/02/skos/core#>
PREFIX foaf: <http://xmlns.com/foaf/0.1/>
PREFIX vcard: <http://www.w3.org/2006/vcard/>
PREFIX dct: <http://purl.org/dc/terms/>
PREFIX void: <http://rdfs.org/ns/void#>
PREFIX time: <http://www.w3.org/2006/time#>
PREFIX qb: <http://purl.org/linked-data/cube#>
PREFIX sdmxa: <http://purl.org/linked-data/sdmx/2009/attribute#>
PREFIX sdmxc: <http://purl.org/linked-data/sdmx/2009/code#>
PREFIX org: <http://www.w3.org/ns/org#>
PREFIX copmv: <http://purl.org/net/opmv/types/common#>
PREFIX opmv: <http://purl.org/net/opmv/ns#>
PREFIX dgu: <http://reference.data.gov.uk/def/reference/>
PREFIX gov: <http://reference.data.gov.uk/def/central-government/>
PREFIX organogram: <http://reference.data.gov.uk/def/organogram/>
PREFIX grade: <http://reference.data.gov.uk/def/civil-service-grade/>
PREFIX payband: <http://reference.data.gov.uk/def/civil-service-payband/>
PREFIX postStatus: <http://reference.data.gov.uk/def/civil-service-post-status/>

CONSTRUCT { 
  ?s ?p ?o . 
  ?graph ?gp ?go .
  ?subset ?sp ?so .
  ?o rdfs:label ?label .
  ?o skos:prefLabel ?prefLabel .
}
WHERE { 
  { 
    ?s foaf:page ?graph .
    ?graph ?gp ?go .
  } 
  UNION
  {
   ?graph void:subset ?subset .
   ?subset ?sp ?so .
   { ?s foaf:page ?subset }
   UNION
   { ?s qb:dataSet ?subset }
   UNION
   { ?s skos:topConceptOf ?subset }
  }
  ?s ?p ?o .
  OPTIONAL {
    ?o rdfs:label ?label
  }
  OPTIONAL {
    ?o skos:prefLabel ?prefLabel
  }
}
EOD;

    $sparql = str_replace('?graph', '<' . $graph . '>', $sparql);

    $params = array(
      'http' => array(
        'method' => 'GET',
        'header' => "Host: " . $_SERVER["HTTP_HOST"],
        'max_redirects' => 1,
        'ignore_errors' => true
      )
    );
    $ctx = stream_context_create($params);
    try {
      $query = array(
        'query' => $check
      );
      $queryString = http_build_query($query);
      $fp = fopen($endpoint . '?' . $queryString, 'rb', false, $ctx);
      if (!$fp) {
        return false;
      } else {
        $response = stream_get_contents($fp);
        if ($response === false || $response === 'FALSE') {
          return false;
        } else {
          $query = array(
            'query' => $sparql
          );
          $queryString = http_build_query($query);
          $fp = fopen($endpoint . '?' . $queryString, 'rb', false, $ctx);
          if (!$fp) {
            return false;
          } else {
            $response = stream_get_contents($fp);
            if ($response === false) {
              return false;
            } else {
              // save the file
              try {
                $written = file_put_contents($fileLocation, $response, LOCK_EX);
              } catch (Exception $e) {
                return false;
              }
            }
          }
        }
      }
    } catch (Exception $e) {
      return false;
    }
  }
  return true;
}

function loadRDF ($rdfLocation) {
  $rdfUrl = "http://organogram.data.gov.uk/$rdfLocation";
  
  if (!file_exists($rdfLocation)) {
    return false;
  }
  
  $rdf = file_get_contents($rdfUrl);
  
  $endpoint = 'http://organogram.data.gov.uk:8080/openrdf-sesame/repositories/organogram/statements';
  $params = array(
    'http' => array(
      'method' => 'PUT',
      'header' => 'Content-Type: application/rdf+xml',
      'max_redirects' => 1,
      'ignore_errors' => true,
      'content' => $rdf
    )
  );
  $query = array(
    'context' => "<$rdfUrl>"
  );
  $queryString = http_build_query($query);
  $ctx = stream_context_create($params);
  try {
    $fp = fopen($endpoint . '?' . $queryString, 'rb', false, $ctx);
    if (!$fp) {
      return false;
    } else {
      $status = $http_response_header[0];
      return $status === 'HTTP/1.1 204 No Content';
    }
  } catch (Exception $e) {
    return false;
  }
  return true;
}

function deleteRDF ($rdfLocation) {
  $rdfUrl = "http://organogram.data.gov.uk/$rdfLocation";  
  $endpoint = 'http://organogram.data.gov.uk:8080/openrdf-sesame/repositories/organogram/statements';
  $params = array(
    'http' => array(
      'method' => 'DELETE',
      'max_redirects' => 1,
      'ignore_errors' => true,
    )
  );
  $query = array(
    'context' => "<$rdfUrl>"
  );
  $queryString = http_build_query($query);
  $ctx = stream_context_create($params);
  try {
    $fp = fopen($endpoint . '?' . $queryString, 'rb', false, $ctx);
    if (!$fp) {
      return false;
    } else {
      $status = $http_response_header[0];
      return $status === 'HTTP/1.1 204 No Content';
    }
  } catch (Exception $e) {
    return false;
  }
  return true;
}

?>
