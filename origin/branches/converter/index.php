<?php
error_reporting(E_ALL);
ini_set('display_errors', true);
class sqliteConverter{
	private $outputFile = '/output.sqlite.sql';
	private $revisedQueries = array();
	public function __construct($contents){
		//convert to single queries
		require_once 'pdo_sqlite_driver_create.php';
		require_once 'pdo_sqlite_driver.php';
		
		$this->parse($contents);
		$this->convert();
		
		//$this->buildTest();
		$this->writeOut();
	}
	
	private function buildTest(){
		$file = './cyberfish.sqlite';
		if (file_exists($file)){
			unlink ($file);
		}
		$pdo = new pdo("sqlite:$file");
		foreach ($this->revisedCreateQueries as $query){
			$result = $pdo->exec($query);
			if ($result === false){
				$e = $pdo->errorinfo();
				echo "error with query $query<br/>";
				print_r($e);
				echo "<br/><hr/>";
			}
		}
		echo "processing " . count($this->revisedInsertQueries) . "<br/>";
		foreach ($this->revisedInsertQueries as $query){
			
			$result = $pdo->exec($query);
			
			$e = $pdo->errorinfo();
			echo "query $query<br/>";
			print_r($e);
			echo "<br/><hr/>";
		}	
	}
	
	private function parse($contents){
		$pattern = '/(?:;|\n)\s*(?P<query>(create)\b(.*?);)(?:\s*(?:create|insert|$))/ims';
		preg_match_all($pattern, $contents, $matches);
		$this->createQueries = $matches['query'];
		$this->replacement = preg_replace($pattern, '', $contents);
		
	}
	
	private function convert(){

		foreach ($this->createQueries as $query){
			$_q = new createquery();
			$returns = $_q->rewriteQuery($query, 'array');
			foreach ($returns as $q){
				$this->revisedCreateQueries[] = $q;
			}
		}
		
		
	}
	
	private function testMultiple($query){
		$return = array();
		$pattern = '/(INSERT.*VALUES\s*)(\(.*\))\s*;/imsx';
		if (!preg_match($pattern, $query, $match)){
			die ('something wrong. Query is '. $query);
		}
		$subPattern = '/(\(.*?\))(?=,)/imsx';
		//extract the subPatterns
		preg_match_all($subPattern, $match[2], $values);
		foreach ($values[1] as $q){
			$q = trim($q);
			if (substr($q, -1,1) !== ';'){
				$q = $q . ';';
			}
			$return[] = $match[1] . $q;
		}
		return $return;
	}
	
	private function writeOut(){
		if (headers_sent()){
			file_put_contents($this->outputFile, implode ("\n\n\n", $this->revisedQueries));
		} else {
			$output = implode ("\n\n\n", $this->revisedCreateQueries);
			$output .= $this->replacement;
			header('content-type: "text/plain"');
			header('content-length: '.strlen($output));
			header('content-disposition: inline');
			echo $output;
			exit;
		}
	}
}

new sqliteConverter(file_get_contents('/wordpress-ansi.sql'));
?>