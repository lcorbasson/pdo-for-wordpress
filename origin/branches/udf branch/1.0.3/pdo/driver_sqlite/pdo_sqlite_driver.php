<?php
/**
 * @package PDO_For_Wordpress
 * @version $Id: pdo_sqlite_driver.php 51767 2008-06-24 11:41:21Z jpadie $
 * @author	Justin Adie, rathercurious.net
 */
 
/**
 *	base class for sql rewriting
 */


class pdo_sqlite_driver{
	
	
	//required variables
	private 	$ifStatements = array();
	private 	$startingQuery = '';
	public 		$_query = '';
	private 	$dateRewrites = array();
	
	
	/**
	*	method to rewrite sqlite queries (or emulate mysql functions)
	*
	*	
	*	@access	public
	*	@param string $query	the query to be rewritten
	*/
	public function rewriteQuery($query, $queryType){
		$this->startingQuery = $query;
		$this->queryType = $queryType;
		$this->_query = $query;
		switch ($this->queryType){
			case 'truncate':
				$this->handleTruncateQuery();
			break;
			case "alter":
				$this->handleAlterQuery();
			break;
			case "create":
				$this->handleCreateQuery();
			break;
			case "describe":
				$this->handleDescribeQuery();
			break;
			case "show":
				$this->handleShowQuery();
			break;
			case "select":
				$this->stripBackTicks();
				$this->handleSqlCount();
				$this->handle_if_statements();
				$this->rewriteBadlyFormedDates();
				$this->rewrite_date_add();
				$this->rewrite_date_sub();
				$this->deleteIndexHints();
				$this->fixdatequoting();
			break;
			case "insert":
				$this->stripBackTicks();
				$this->rewrite_insert_ignore();
				$this->rewriteBadlyFormedDates();
				break;
			case "update":
				$this->stripBackTicks();
				$this->rewrite_update_ignore();
				$this->rewriteBadlyFormedDates();
				$this->fixdatequoting();
			case "delete":
			case "replace":
				$this->stripBackTicks();
				$this->rewriteBadlyFormedDates();
				$this->rewrite_date_add();
				$this->rewrite_date_sub();
				$this->stripBackTicks();
				$this->rewriteLimitUsage();
				$this->fixdatequoting();
				break;
			case "optimize":
				$this->rewrite_optimize();
			default:
		}
		return $this->_query;
	}
	
	
	/**
	*	method to process results of an unbuffered query
	*
	*	@access	public
	*	@param array $array (associative array of results)
	*	@return mixed	the cleansed array of query results
	*/
	public function processResults($array = array()){
		$this->_results = $array;		
		//the If results...
		foreach($this->ifStatements as $ifStatement){
			foreach($this->_results as $key=>$row){
				//could use eval here.
				$operation = false;
				switch ($ifStatement['operator']){
					case "=":
						$operation = ($ifStatement['param1_as'] == $ifStatement['param2_as']);
					break;
					case ">":
						$operation = ($ifStatement['param1_as'] > $ifStatement['param2_as']);
					break;
					case "<":
						$operation = ($ifStatement['param1_as'] < $ifStatement['param2_as']);
					break;
					case "<=":
						$operation = ($ifStatement['param1_as'] <= $ifStatement['param2_as']);
					break;
					case ">=":
						$operation = ($ifStatement['param1_as'] >= $ifStatement['param2_as']);
					break;
					case "isnull":
					case "is null":
						$operation = ($ifStatement['param1_as'] === NULL);
					case "like":
						$l = $r = false;
						//determine type of LIKE
						if (substr($ifStatement['param2_as'], 0, 1) == '%'){
							$l = true;
						}
						if(substr($ifStatement['param2_as'], -1, 1) == '%'){
							$r = true;
						}
						if ($l && $r){
							$operation = stristr($ifStatement['param2_as'], $ifStatement['param1_as']);
						}
						if (!$l && !$r){
							$operation = ($ifStatement['param1_as'] == $ifStatement['param2_as']);
						}
						if ($l && !$r){
							$operation = (substr($ifStatement['param1_as'], -1, strlen($ifStatement['param2_as'])-1) == substr($ifStatement['param2_as'],0, strlen($ifStatement['param2_as'] -1)));
						}
						if (!$l && $r){
							$operation = (substr($ifStatement['param1_as'], 0, strlen($ifStatement['param2_as'])-1) == substr($ifStatement['param2_as'],1, strlen($ifStatement['param2_as'] -1)));			
						}
					break;
				}
				//clean up the row
				unset($row[$ifStatement['param1_as']]);
				unset($row[$ifStatement['param2_as']]);
				$row[$ifStatement['as']] = ($operation) ? $ifStatement['trueval'] : $ifStatement['falseval'];
				$this->_results[$key] = $row;
			} //end of foreach $array	
		} //end of IF processing
		
		return $this->_results;
	}
	
	/**
	 *	method to dummy the SHOW TABLES query
	 */
	private function handleShowQuery(){
		$pattern = '/^\\s*SHOW\\s*TABLES\\s*(LIKE\\s*.*)?/im';
		$result = preg_match($pattern, $this->_query, $matches);
		if (!empty($matches[1])){
			$suffix = ' AND name '.$matches[1];		
		} else {
			$suffix = '';
		}
		$this->_query = "SELECT name FROM sqlite_master WHERE type = 'table'" . $suffix . ' ORDER BY name DESC';
		$this->showQuery = true;	
	}
	

	


	
	/**
	*	rewrites if statements to retrieve all columns in the query
	*
	*	the different field elements are captured in a variable through the callback
	*	function and then sorted out in the postprocessing
	*/
	private function handle_if_statements(){
		$pattern = "/\\s*if\\s*\((.*?)(=|>=|>|<=|<|!=|LIKE|isnull|is null)([^,]*),([^,]*),(.*?)\)\\s*as\\s*(\w*)/imsx";
		$query = preg_replace_callback($pattern, array($this, 'emulateIfQuery'), $this->_query);
		$this->_query = $query;
	}

	/**
	*	method to strip all column qualifiers (backticks) from a query
	*/
	private function stripBackTicks(){
		$this->_query = str_replace("`", "", $this->_query);
	}
	
	/**
	*	callback function for handle_if_statements
	*/
	private function emulateIfQuery($matches){
		$tmp_1 = 't_' . md5(uniqid('t_', true));
		$tmp_2 = 't_' . md5(uniqid('t_', true));
		$this->IFStatements[] = array (	'param1'=>$matches[1],
										'param1_as'=>$tmp_1,
										'operator'=>strtolower($matches[2]),
										'param2'=>$matches[3],
										'param2_as'=>$tmp_2,
										'trueval'=>$matches[4],
										'falseval'=>$matches[5],
										'as'=>$matches[6]);
		return " $matches[1] as $tmp_1, $matches[3] as $tmp_2 ";
	}
	
	
	
	
	/**
	*	function that abstracts a case insensitive search and replace
	*
	*	implemented for backward compatibility with pre 5.0 versions of 
	*	php.  not really needed as php4 won't work with this class anyway
	*
	*	@param string $search	the needle
	*	@param string $replace	the replacement text
	*	@param string $subject	the haystack
	*/
	private function iStrReplace($search, $replace, $subject){
		if (function_exists('str_ireplace')){
			return str_ireplace($search, $replace, $subject);
		} else {
			return preg_replace("/$search/i", $replace, $subject);
		}
	}
	
	/**
	*	method to emulate the SQL_CALC_FOUND_ROWS placeholder for mysql
	*
	*	this is really yucky. we create a new instance of the database class,
	*	rewrite the query to use a count(*) syntax without the LIMIT
	*	run the rewritten query, grab the recordset with the number of rows in it
	*	and write it to a special variable in the common abstraction object
	*	then delete the SQL_CALC_FOUND_ROWS keyword from the base query and
	*	pass back to the main process.
	*/
	private function handleSqlCount(){
		if (stripos($this->_query, 'SQL_CALC_FOUND_ROWS') === false){
			//do nothing
		} else {
			global $wpdb;
			//echo "handling count rows<br/>";
			//first strip the code
			$this->_query = $this->istrreplace('SQL_CALC_FOUND_ROWS', ' ', $this->_query);
			//echo "prepped query for main use = ". $this->_query ."<br/>";
			
			$unLimitedQuery = preg_replace('/\\bLIMIT\s*.*/imsx', '', $this->_query);
			$unLimitedQuery = $this->transform2Count($unLimitedQuery);
			//echo "prepped query for count use is $unLimitedQuery<br/>";
			$_wpdb = new pdo_db(DB_USER, DB_PASSWORD, DB_NAME, DB_HOST, DB_TYPE);
			
			$result = $_wpdb->query($unLimitedQuery);
			$wpdb->dbh->foundRowsResult = $_wpdb->last_result;
			//echo "number of records stored is $rowcount<br/>";
			
		}
	}
	
	/**
	*	transforms a select query to a select count(*)
	*
	*	@param	string $query	the query to be transformed
	*	@return	string			the transformed query
	*/
	private function transform2Count($query){
		$pattern = '/^\\s*select\\s*(distinct)?.*?from\b/imsx';
		$_query = preg_replace($pattern, 'Select \\1 count(*) from ', $query);
		return $_query;
	}
	
	

	/**
	*	rewrites the insert ignore phrase for sqlite
	*/
	private function rewrite_insert_ignore(){
		$this->_query = $this->istrreplace('insert ignore', 'insert or ignore ', $this->_query); 
	}

	/**
	*	rewrites the update ignore phrase for sqlite
	*/
	private function rewrite_update_ignore(){
		$this->_query = $this->istrreplace('update ignore', 'update or ignore ', $this->_query); 
	}
	
	
	/**
	*	rewrites usage of the date_add function for sqlite
	*/
	private function rewrite_date_add(){
		//(date,interval expression unit)
		$pattern = '/\\s*date_add\\s*\(([^,]*),([^\)]*)\)/imsx';
		$this->_query = preg_replace_callback($pattern, array($this,'_rewrite_date_add'), $this->_query);
	}
	
	/**
	*	callback function for rewrite_date_add()
	*/
	private function _rewrite_date_add($matches){
		$date = $matches[1];
		$_params = $params = array();
		$params = explode (" ", $matches[2]);
		//cleanse the array as sqlite is quite picky
		foreach ($params as $param){
			$_p = trim ($param);
			if (!empty($_p)){
				$_params[] = $_p;
			}
		}
		//we should be after items 1 and 2
		return " datetime($date,'$_params[1] $_params[2]') ";
	}
	
	/**
	 *	method to rewrite date_sub
	 *
	 *	required for drain Hole...
	 */
	private function rewrite_date_sub(){
		//(date,interval expression unit)
		$pattern = '/\\s*date_sub\\s*\(([^,]*),([^\)]*)\)/imsx';
		$this->_query = preg_replace_callback($pattern, array($this,'_rewrite_date_sub'), $this->_query);
	}
	
	/**
	*	callback function for rewrite_date_sub()
	*/
	private function _rewrite_date_sub($matches){
		$date = $matches[1];
		$_params = $params = array();
		$params = explode (" ", $matches[2]);
		//cleanse the array as sqlite is quite picky
		foreach ($params as $param){
			$_p = trim ($param);
			if (!empty($_p)){
				$_params[] = $_p;
			}
		}
		//we should be after items 1 and 2
		return " datetime($date,'-$_params[1] $_params[2]') ";
	}
	
	/**
	*	handles the create query
	*
	*	this method invokes a separate class for the query rewrites
	*	as the create queries are complex to rewrite and I did not 
	*	want to clutter up the function namespace where unnecessary to do so
	*/
	private function handleCreateQuery(){
		require_once PDODIR.'/driver_sqlite/pdo_sqlite_driver_create.php';
		$q = new createQuery();
		$this->_query = $q->rewriteQuery($this->_query);
		$q = NULL;
	}
	
	/**
	*	dummies the alter queries as sqlite does not have a great method for handling them
	*/
	private function handleAlterQuery(){
		$this->_query = "select 1=1";
	}
	
	/**
	*	dummies describe queries
	*/
	private function handleDescribeQuery(){
		$this->_query = "select 1=1";
	}
	
	/**
	*	the new update() method of wp-db makes for some reason insists on adding LIMIT
	*	to the end of each update query. sqlite does not support these.
	*	let's hope that the queries have not been malformed in reliance on this LIMIT clause.
	*	decision decision taken to leave the LIMIT clause in the update() method and rewrite on the fly
	*	so mysql pdo support can be maintained and for portability to other languages.
	*/
	private function rewriteLimitUsage(){
		$pattern = '/\\s*LIMIT\\s*[0-9]$/i';
		$this->_query = preg_replace($pattern, '', $this->_query);
	}
	

	
	
	private function handleTruncateQuery(){
		$pattern = '/truncate table (.*)/im';
		$this->_query = preg_replace($pattern, 'DELETE FROM $1', $this->_query);
	}
	/**
	 * rewrites use of Optimize queries in mysql for sqlite.
	 * 
	 * no granularity is used here.  an optimize table will vacuum the whole database. 
	 * probably not a bad thing
	 *  
	 */
	private function rewriteOptimize(){
		$this->_query ="VACUUM";
	}
	
	/**
	 * function to ensure date inserts are properly formatted for sqlite standards
	 * 
	 * some wp UI interfaces (notably the post interface) badly composes the day part of the date
	 * leading to problems in sqlite sort ordering etc.
	 * 
	 * @return void
	 */
	private function rewriteBadlyFormedDates(){
		$pattern = '/([12]\d{3,}-\d{2}-)(\d )/ims';
		$this->_query = preg_replace($pattern, '${1}0$2', $this->_query);
	}
	
	/**
	 * function to remove unsupported index hinting from mysql queries
	 * 
	 * @return void 
	 */
	private function deleteIndexHints(){
		$pattern = '/use\s+index\s*\(.*?\)/i';
		$this->_query = preg_replace($pattern, '', $this->_query);
	}
	
	
	/**
	 * method to fix inconsistent use of quoted, unquoted etc date values in query function
	 * 
	 * this is ironic, given the above rewritebadlyformed dates method 
	 * 
	 * examples 
	 * where month(fieldname)=08 becomes month(fieldname)='8'
	 * where month(fieldname)='08' becomes month(fieldname)='8'
	 * 
	 * @return void
	 */
	private function fixDateQuoting(){
		$pattern = '/(month|year|second|day|minute|hour)\s*\((.*?)\)\s*=\s*["\']?(\d{1,4})[\'"]?\s*/ei';	//	$pattern ='/(month|year|second|day|minute|hour)(\s*\(.*?\)\s*)=\s*("|\')?(\d{1,4})$3\s/ei';
		$this->_query = preg_replace($pattern, "'\\1(\\2)=\'' . intval('\\3') . '\' ' ", $this->_query);
	}
}
?>