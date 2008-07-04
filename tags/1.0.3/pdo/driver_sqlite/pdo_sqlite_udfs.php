<?php

class PDO_SQLITE_UDFS{
	private $functions = array(	
								'month'=> 'month',
								'year'=> 'year',
								'day' => 'day',
								'unix_timestamp' => 'unix_timestamp',
								'now'=>'now',
								'char_length', 'char_length',
								'md5'=>'md5',
								'curdate'=>'now',
								'rand'=>'rand',
								'substring'=>'substring',
								'dayofmonth'=>'day',
								'second'=>'second',
								'minute'=>'minute',
								'hour'=>'hour',
								'date_format'=>'dateformat'
								);
								
	public function month($field){
		$t = strtotime($field);
		$output = (date('n', $t));
		return (string) $output;
	}
	public function year($field){
		$t = strtotime($field);
		$output = date('Y', $t);
		return  $output;
	}
	public function day($field){
		$t = strtotime($field);
		return date('d', $t);
	}
	public function unix_timestamp($field){
		return strtotime($field);
	}
	public function second($field){
		$t = strtotime($field);
		return  date("s", $t);
	}
	public function minute($field){
		$t = strtotime($field);
		return  date("i", $t);
	}
	public function hour($field){
		$t = strtotime($field);
		return date("H", $t);
	}
	public function now(){
		return date("Y-m-d H:i:s");
	}
	public function char_length($field){
		return strlen($field);
	}
	public function md5($field){
		return md5($field);
	}
	public function rand(){
		return rand(0,1);
	}
	public function substring($text, $pos, $len=null){
		return substr($text, $pos-1, $len);
	}
	public function dateformat($date, $format){
		$mysql_php_dateformats = array ( '%a' => 'D', '%b' => 'M', '%c' => 'n', '%D' => 'jS', '%d' => 'd', '%e' => 'j', '%H' => 'H', '%h' => 'h', '%I' => 'h', '%i' => 'i', '%j' => 'z', '%k' => 'G', '%l' => 'g', '%M' => 'F', '%m' => 'm', '%p' => 'A', '%r' => 'h:i:s A', '%S' => 's', '%s' => 's', '%T' => 'H:i:s', '%U' => 'W', '%u' => 'W', '%V' => 'W', '%v' => 'W', '%W' => 'l', '%w' => 'w', '%X' => 'Y', '%x' => 'o', '%Y' => 'Y', '%y' => 'y', );
		$t = strtotime($date);
		$format = strtr($format, $mysql_php_dateformats);
		$output =  date($format, $t);
		return $output;
	}
	
	public function __construct(&$pdo){
		foreach ($this->functions as $f=>$t){
			$pdo->sqliteCreateFunction($f, array($this, $t));
		}
	}
}
?>