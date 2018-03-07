<?php

@include "config.php";			// please copy the config.sample.php and edit the correct fields

@include "./php-xbase/src/XBase/Table.php";
@include "./php-xbase/src/XBase/Column.php";
@include "./php-xbase/src/XBase/Record.php";
@include "./php-xbase/src/XBase/Memo.php";
@include "./classes/DBFhandler.php";

use XBase\Table;

 // Initializing vars
ini_set( 'memory_limit', '2048M' );
set_time_limit( 0 );

$time_start = time();
$files = scandir($xbase_dir) or die ("Error! Could not open directory '$xbase_dir'.");
$conn = new mysqli($db_host, $db_uname, $db_passwd, $db_name) or die ("Error connecting to mysql $mysqli->connect_error");
mysqli_set_charset($conn, 'utf8');



foreach ($files as $file) {
  switch ($file) {
  case (preg_match("/dbf$/i", $file) ? true : false):
	  print_r("DBF: $file\n");	
	  $tbl = substr($file,0,strlen($file)-4);
	  echo ("$tbl.sql");
      $sqlFile = fopen("convertedTables/$tbl.sql", "w") or die("Unable to open file!");	    
  	dbftoPostgres($file, $sqlFile);
  	break;
  default:
  	print_r("Other file: $file\n");
  }

}
$time_end = time();
"\n\nImport finished! Time spent: ". round( ( $time_end - $time_start ) / 60, 2 ) ." minutes\n";

function dbftoPostgres($file, $sqlFile) {
	// Path to dbase file
	global $xbase_dir;
	global $conn;
	global $die_on_mysql_error;

	$db_path = sprintf("%s/%s",$xbase_dir,$file);
	// Open dbase file
	$table = new Table($db_path);
	$tbl = substr($file,0,strlen($file)-4);
	print ( "Beware: space of C (Character field) - will be double in MySQL for the special case of a full special-chars-only field\n" );
	print_r ("$tbl");
	$line = array();
	$line[]="id SERIAL";		

	foreach ($table->getColumns() as $column) {
		print_r("\t$column->name ($column->type / $column->length)\n");
		switch($column->type) {
			case 'C':	// Character field
				$line[]= "$column->name VARCHAR(" . $column->length * 2 . ")"; // double space for utf-8 conversion of special chars
				break;
			case 'F':	// Floating Point
				$line[]= "$column->name FLOAT";
				break;
			case 'N':	// Numeric
				$line[]= "$column->name NUMERIC(" . $column->length . ")";
				break;
			case 'L':	// Logical - ? Y y N n T t F f (? when not initialized).
				$line[]= "$column->name TINYINT";
				break;
			case 'D':	// Date
				$line[]= "$column->name DATE";
				break;
			case 'T':	// DateTime
				$line[]= "$column->name DATETIME";
				break;
			case 'M':	// Memo type field
			default:
				$line[]= "$column->name TEXT";
				break;
		}
	}

	$str = implode(",",$line);
	
	$sql = 'BEGIN; SET statement_timeout=60000; DROP TABLE IF EXISTS '.$tbl.'; CREATE TABLE '.$tbl.'('.$str.'); ';

	fwrite($sqlFile, $sql);
	$table->close();

	// Import using dbf + fpt files (for MEMO data...)
	$fpt_file = str_replace( '.dbf', '.fpt', $db_path );
	$fpt_path = ( file_exists( $fpt_file ) ? $fpt_file : '' );
	import_dbf_to_postgres( $table, $tbl, $db_path, $fpt_path, $sqlFile );
//	import_dbf($db_path, $tbl);
}

// Not used (see above)
function import_dbf($db_path, $tbl) {
	global $conn;
	global $die_on_mysql_error;
	// print_r ("$db_path\n");
	$table = new Table($db_path);

	print_r ("$table->recordCount\n");
	print_r (sizeof($table->columns));
	$i = 0;
	while ($record=$table->nextRecord()) {
		$fields = array();
		$line = array();
		foreach ($record->getColumns() as $column) {
			$fields[]=$column->name;
			// print_r("$column->name\n");
			switch($column->type) {
				case 'C':	// Character field
				case 'M':	// Memo type field
					$line[]= sprintf("'%s'", $record->getObject($column) );
					break;
				case 'F':	// Floating Point
					$line[]=sprintf("%7.2f", $record->getObject($column) );
					break;
				case 'N':	// Numeric
					$line[]=sprintf("'%s'", $record->getObject($column) );
					break;
				case 'L':	// Logical - ? Y y N n T t F f (? when not initialized).
					// $line[] = sprintf("%d", ($record->getBoolean($column) ? 1 : 0) );
					$line[] = sprintf("%d", $record->getString($column->name) );
					break;
				case 'T':	// DateTime
				case 'D':	// Date
					$line[]= sprintf("'%s'", strftime("%Y-%m-%d %H:%M", $record->getObject($column) ) );
					break;
			}
		}

		$val = implode(",",$line);
		$col = implode(",",$fields);

		if($GLOBALS['from_encoding']!="")$val = iconv($GLOBALS['from_encoding'], 'UTF-8', $val);

		$sql = "INSERT INTO `$tbl` ($col) VALUES ($val)\n";
		// print_r ("$sql");
		if ($conn->query("$sql") === TRUE) {
			$i++;
			if ( $i % 100 == 0 ) {
				echo "$i records inserted in $tbl\n";
			}
			die;
		} else {
			echo "Error SQL: ".$conn->error ." >> $sql \n";
			if ($die_on_mysql_error) {
				die;
			}
		}
	}
	$table->close();
	echo "Table $tbl imported\n";

}


function import_dbf_to_postgres( $table, $tbl, $dbf_path, $fpt_path, $sqlFile ) {
	echo "Initializing import: Table $tbl\n";
	global $conn;
	global $die_on_mysql_error;
	$i = 0;
	$Test = new DBFhandler( $dbf_path, $fpt_path );
	$sql1 = "INSERT INTO $tbl (";
	$sql2 = "values";
    $sql = "";
	$row = array();
	foreach ($table->getColumns() as $column) {
		$sql1 .="$column->name,";
	}
	$sql1=rtrim($sql1,", ");
	$sql1.=")";

    while ( ($Record = $Test->GetNextRecord( true )) and ! empty( $Record ) ) {
		$a = 0;
		$sql3="(";
		$sql4="";
        foreach ( $Record[ 'type' ] as $key => $type ) {
            $val = $Record[ 'value' ][ $key ];
            $key = (strpos($key, 0x00) !== false ) ? substr($key, 0, strpos($key, 0x00)) : $key;

            if ( $val == '{BINARY_PICTURE}' ) {
                continue;
            }
            switch( $type ) {
	            case 'C':	// Character field
	            case 'M':	// Memo type field
		            $val = sprintf("'%s'", $val );
		            break;
	            case 'F':	// Floating Point
		            $val = sprintf("%7.2f", $val );
		            break;
	            case 'N':	// Numeric
		            $val = sprintf("%s", $val );
		            break;
	            case 'L':	// Logical - ? Y y N n T t F f (? when not initialized).
	                    $val = sprintf("%d", ( ( strtolower($val) === 't' || strtolower($val) === 'y' ) ? 1 : 0) );
		            break;
	            case 'T':	// DateTime
	            case 'D':	// Date
		            $val = sprintf("'%s'", strftime("%Y-%m-%d %H:%M", strtotime($val) ) );
		            break;
            }
            $val = str_replace( "'", "", $val );
            if($GLOBALS['from_encoding']!="")$val = iconv($GLOBALS['from_encoding'], 'UTF-8', $val);
			$a = $a + 1;
            if ( $a == 1 ) {
                $sql3 .="'" . trim( $val ) . "'";
            } else {
                $sql4 .=",'$val'";
            }
        }
		$sql = "$sql3 $sql4),";
		array_push($row, $sql);		
		$i++;		
		if ( $i % 100 == 0 ) {
			echo ".";
		}

  }
  array_pop($row);  
  $data=implode("", $row);
  $sql= "$sql1 $sql2 $data";
  $sql=rtrim($sql,", ");
  $sql="$sql; COMMIT;";
  
  	fwrite($sqlFile, utf8_encode($sql));
  	fclose($sqlFile);
	echo "Table $tbl imported\n";

}

