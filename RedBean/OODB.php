<?php 
/**
 * RedBean OODB (object oriented database)
 * @package 		RedBean/OODB.php
 * @description		Core class for the RedBean ORM pack
 * @author			Gabor de Mooij
 * @license			BSD
 */
class RedBean_OODB {


	/**
	 * Indicates how long one can lock an item,
	 * defaults to ten minutes
	 * If a user opens a bean and he or she does not
	 * perform any actions on it others cannot modify the
	 * bean during this time interval.
	 * @var unknown_type
	 */
	private $locktime = 10;

	/**
	 * a standard adapter for use with RedBean's MYSQL Database wrapper or
	 * ADO library
	 * @var RedBean_DBAdapter
	 */
	private $db;

	/**
	 * 
	 * @var boolean
	 */
	private $locking = true;
        /**
         *
         * @var string $pkey - a fingerprint for locking
         */
        public $pkey = false;

		/**
		 * Indicates that a rollback is required
		 * @var unknown_type
		 */
		private $rollback = false;
		
		/**
		 * 
		 * @var $this
		 */
		private $me = null;

		/**
		 * 
		 * Indicates the current engine
		 * @var string
		 */
		private $engine = "myisam";

		/**
		 * @var boolean $frozen - indicates whether the db may be adjusted or not
		 */
		private $frozen = false;

		/**
		 * @var QueryWriter
		 */
		private $writer;


                private $beanchecker;
                private $gc;
                private $classGenerator;
                private $filter;
                private $search;
                private $optimizer;
                private $beanstore;
                private $association;
                private $lockmanager;
                private $tree;
                private $tableregister;
                private $finder;
                private $lister;
                private $dispenser;


                private function __construct( $filter = false ) {
                    $this->filter = new RedBean_Mod_Filter_Strict();
                    $this->beanchecker = new RedBean_Mod_BeanChecker();
                    $this->gc = new RedBean_Mod_GarbageCollector();
                    $this->classGenerator = new RedBean_Mod_ClassGenerator( $this );
                    $this->search = new RedBean_Mod_Search( $this );
                    $this->optimizer = new RedBean_Mod_Optimizer( $this );
                    $this->beanstore = new RedBean_Mod_BeanStore( $this );
                    $this->association = new RedBean_Mod_Association( $this );
                    $this->lockmanager = new RedBean_Mod_LockManager( $this );
                    $this->tree = new RedBean_Mod_Tree( $this );
                    $this->tableregister = new RedBean_Mod_TableRegister( $this );
                    $this->finder = new RedBean_Mod_Finder( $this );
                }

                public function getFilter() {
                    return $this->filter;
                }

                public function setFilter( RedBean_Mod_Filter $filter ) {
                    $this->filter = $filter;
                }
               
                public function getWriter() {
                    return $this->writer;
                }

                public function isFrozen() {
                    return (boolean) $this->frozen;
                }

		/**
		 * Closes and unlocks the bean
		 * @return unknown_type
		 */
		public function __destruct() {

			$this->releaseAllLocks();
			
			$this->db->exec( 
				$this->writer->getQuery("destruct", array("engine"=>$this->engine,"rollback"=>$this->rollback))
			);
			
		}



		
		/**
		 * Toggles Forward Locking
		 * @param $tf
		 * @return unknown_type
		 */
		public function setLocking( $tf ) {
			$this->locking = $tf;
		}

		public function getDatabase() {
			return $this->db;
		}
		
		public function setDatabase( RedBean_DBAdapter $db ) {
			$this->db = $db;
		} 
		
		/**
		 * Gets the current locking mode (on or off)
		 * @return unknown_type
		 */
		public function getLocking() {
			return $this->locking;
		}
	
		
		/**
		 * Toggles optimizer
		 * @param $bool
		 * @return unknown_type
		 */
		public function setOptimizerActive( $bool ) {
			$this->optimizer = (boolean) $bool;
		}
		
		/**
		 * Returns state of the optimizer
		 * @param $bool
		 * @return unknown_type
		 */
		public function getOptimizerActive() {
			return $this->optimizer;
		}
		
		/**
		 * keeps the current instance
		 * @var RedBean_OODB
		 */
		private static $instance = null;
		
		/**
		 * Singleton
		 * @return unknown_type
		 */
		public static function getInstance() {
			if (self::$instance === null) {
				self::$instance = new RedBean_OODB;
			}
			return self::$instance;
		}
		
		/**
		 * Checks whether a bean is valid
		 * @param $bean
		 * @return unknown_type
		 */
		public function checkBean(RedBean_OODBBean $bean) {
                    if (!$this->db) {
                        throw new RedBean_Exception_Security("No database object. Have you used kickstart to initialize RedBean?");
                    }
                    return $this->beanchecker->check( $bean );
		}

		/**
		 * same as check bean, but does additional checks for associations
		 * @param $bean
		 * @return unknown_type
		 */
		public function checkBeanForAssoc( $bean ) {

			//check the bean
			$this->checkBean($bean);

			//make sure it has already been saved to the database, else we have no id.
			if (intval($bean->id) < 1) {
				//if it's not saved, save it
				$bean->id = $this->set( $bean );
			}

			return $bean;

		}

		/**
		 * Returns the current engine
		 * @return unknown_type
		 */
		public function getEngine() {
			return $this->engine;
		}

		/**
		 * Sets the current engine
		 * @param $engine
		 * @return unknown_type
		 */
		public function setEngine( $engine ) {

			if ($engine=="myisam" || $engine=="innodb") {
				$this->engine = $engine;
			}
			else {
				throw new Exception("Unsupported database engine");
			}

			return $this->engine;

		}

		/**
		 * Will perform a rollback at the end of the script
		 * @return unknown_type
		 */
		public function rollback() {
			$this->rollback = true;
		}
		
		public function set( RedBean_OODBBean $bean ) {
                    return $this->beanstore->set($bean);
                }


		/**
		 * Infers the SQL type of a bean
		 * @param $v
		 * @return $type the SQL type number constant
		 */
		public function inferType( $v ) {
			
			$db = $this->db;
			$rawv = $v;
			
			$checktypeSQL = $this->writer->getQuery("infertype", array(
				"value"=> $this->db->escape(strval($v))
			));
			
			
			$db->exec( $checktypeSQL );
			$id = $db->getInsertID();
			
			$readtypeSQL = $this->writer->getQuery("readtype",array(
				"id"=>$id
			));
			
			$row=$db->getRow($readtypeSQL);
			
			
			$db->exec( $this->writer->getQuery("reset_dtyp") );
			
			$tp = 0;
			foreach($row as $t=>$tv) {
				if (strval($tv) === strval($rawv)) {
					return $tp;
				}
				$tp++;
			}
			return $tp;
		}

		/**
		 * Returns the RedBean type const for an SQL type
		 * @param $sqlType
		 * @return $typeno
		 */
		public function getType( $sqlType ) {

			if (in_array($sqlType,$this->writer->sqltype_typeno)) {
				$typeno = $this->writer->sqltype_typeno[$sqlType];
			}
			else {
				$typeno = -1;
			}

			return $typeno;
		}

		/**
		 * Initializes RedBean
		 * @return bool $true
		 */
		public function init( RedBean_QueryWriter $querywriter, $dontclose = false ) {

			$this->writer = $querywriter;
		

			//prepare database
			if ($this->engine === "innodb") {
				$this->db->exec($this->writer->getQuery("prepare_innodb"));
				$this->db->exec($this->writer->getQuery("starttransaction"));
			}
			else if ($this->engine === "myisam"){
				$this->db->exec($this->writer->getQuery("prepare_myisam"));
			}


			//generate the basic redbean tables
			//Create the RedBean tables we need -- this should only happen once..
			if (!$this->frozen) {
				
				$this->db->exec($this->writer->getQuery("clear_dtyp"));
					
				$this->db->exec($this->writer->getQuery("setup_dtyp"));
						
				$this->db->exec($this->writer->getQuery("setup_locking"));
						
				$this->db->exec($this->writer->getQuery("setup_tables"));
			}
			
			//generate a key
			if (!$this->pkey) {
				$this->pkey = str_replace(".","",microtime(true)."".mt_rand());
			}

			return true;
		}

		/**
		 * Freezes the database so it won't be changed anymore
		 * @return unknown_type
		 */
		public function freeze() {
			$this->frozen = true;
		}

		/**
		 * UNFreezes the database so it won't be changed anymore
		 * @return unknown_type
		 */
		public function unfreeze() {
			$this->frozen = false;
		}

		/**
		 * Returns all redbean tables or all tables in the database
		 * @param $all if set to true this function returns all tables instead of just all rb tables
		 * @return array $listoftables
		 */
		public function showTables( $all=false ) {
                        return $this->tableregister->getTables($all);
                }

		/**
		 * Registers a table with RedBean
		 * @param $tablename
		 * @return void
		 */
		public function addTable( $tablename ) {
                        return $this->tableregister->register( $tablename);
		}

		/**
		 * UNRegisters a table with RedBean
		 * @param $tablename
		 * @return void
		 */
		public function dropTable( $tablename ) {
                        return $this->tableregister->unregister( $tablename );
		}

		/**
		 * Quick and dirty way to release all locks
		 * @return unknown_type
		 */
		public function releaseAllLocks() {
			$this->db->exec($this->writer->getQuery("release",array("key"=>$this->pkey)));
        	}

		/**
		 * Opens and locks a bean
		 * @param $bean
		 * @return unknown_type
		 */
		public function openBean( $bean, $mustlock=false) {
                        $this->checkBean( $bean );
                        $this->lockmanager->openBean( $bean, $mustlock );
            	}

		
		/**
		 * Gets a bean by its primary ID
		 * @param $type
		 * @param $id
		 * @return RedBean_OODBBean $bean
		 */
		public function getById($type, $id, $data=false) {
                        return $this->beanstore->get($type,$id,$data);
                }
                
		/**
		 * Checks whether a type-id combination exists
		 * @param $type
		 * @param $id
		 * @return unknown_type
		 */
		public function exists($type,$id) {

			$db = $this->db;
			$id = intval( $id );
			$type = $db->escape( $type );

			//$alltables = $db->getCol("show tables");
			$alltables = $this->showTables();

			if (!in_array($type, $alltables)) {
				return false;
			}
			else {
				$no = $db->getCell( $this->writer->getQuery("bean_exists",array(
					"type"=>$type,
					"id"=>$id
				)) );
				if (intval($no)) {
					return true;
				}
				else {
					return false;
				}
			}
		}

		/**
		 * Counts occurences of  a bean
		 * @param $type
		 * @return integer $i
		 */
		public function numberof($type) {

			$db = $this->db;
			$type = $this->filter->table( $db->escape( $type ) );

			$alltables = $this->showTables();

			if (!in_array($type, $alltables)) {
				return 0;
			}
			else {
				$no = $db->getCell( $this->writer->getQuery("count",array(
					"type"=>$type
				)));
				return intval( $no );
			}
		}
		
		/**
		 * Gets all beans of $type, grouped by $field.
		 *
		 * @param String Object type e.g. "user" (lowercase!)
		 * @param String Field/parameter e.g. "zip"
		 * @return Array list of beans with distinct values of $field. Uses GROUP BY
		 * @author Alan J. Hogan
		 **/
		function distinct($type, $field)
		{
			//TODO: Consider if GROUP BY (equivalent meaning) is more portable 
			//across DB types?
			$db = $this->db;
			$type = $this->filter->table( $db->escape( $type ) );
			$field = $db->escape( $field );
		
			$alltables = $this->showTables();

			if (!in_array($type, $alltables)) {
				return array();
			}
			else {
				$ids = $db->getCol( $this->writer->getQuery("distinct",array(
					"type"=>$type,
					"field"=>$field
				)));
				$beans = array();
				if (is_array($ids) && count($ids)>0) {
					foreach( $ids as $id ) {
						$beans[ $id ] = $this->getById( $type, $id , false);
					}
				}
				return $beans;
			}
		}

		/**
		 * Simple statistic
		 * @param $type
		 * @param $field
		 * @return integer $i
		 */
		private function stat($type,$field,$stat="sum") {

			$db = $this->db;
			$type = $this->filter->table( $db->escape( $type ) );
			$field = $this->filter->property( $db->escape( $field ) );
			$stat = $db->escape( $stat );

			$alltables = $this->showTables();

			if (!in_array($type, $alltables)) {
				return 0;
			}
			else {
				$no = $db->getCell($this->writer->getQuery("stat",array(
					"stat"=>$stat,
					"field"=>$field,
					"type"=>$type
				)));
				return floatval( $no );
			}
		}

		/**
		 * Sum
		 * @param $type
		 * @param $field
		 * @return float $i
		 */
		public function sumof($type,$field) {
			return $this->stat( $type, $field, "sum");
		}

		/**
		 * AVG
		 * @param $type
		 * @param $field
		 * @return float $i
		 */
		public function avgof($type,$field) {
			return $this->stat( $type, $field, "avg");
		}

		/**
		 * minimum
		 * @param $type
		 * @param $field
		 * @return float $i
		 */
		public function minof($type,$field) {
			return $this->stat( $type, $field, "min");
		}

		/**
		 * maximum
		 * @param $type
		 * @param $field
		 * @return float $i
		 */
		public function maxof($type,$field) {
			return $this->stat( $type, $field, "max");
		}


		/**
		 * Unlocks everything
		 * @return unknown_type
		 */
		public function resetAll() {
			$sql = $this->writer->getQuery("releaseall");
			$this->db->exec( $sql );
			return true;
		}

		/**
		 * Loads a collection of beans -fast-
		 * @param $type
		 * @param $ids
		 * @return unknown_type
		 */
		public function fastLoader( $type, $ids ) {
			
			$db = $this->db;
			
			
			$sql = $this->writer->getQuery("fastload", array(
				"type"=>$type,
				"ids"=>$ids
			)); 
			
			return $db->get( $sql );
			
		}
		
		/**
		 * Allows you to fetch an array of beans using plain
		 * old SQL.
		 * @param $rawsql
		 * @param $slots
		 * @param $table
		 * @param $max
		 * @return array $beans
		 */
		public function getBySQL( $rawsql, $slots, $table, $max=0 ) {

                        return $this->search->sql( $rawsql, $slots, $table, $max );
                       
		}
		
		
     /** 
     * Finds a bean using search parameters
     * @param $bean
     * @param $searchoperators
     * @param $start
     * @param $end
     * @param $orderby
     * @return unknown_type
     */
    public function find(RedBean_OODBBean $bean, $searchoperators = array(), $start=0, $end=100, $orderby="id ASC", $extraSQL=false) {
        return $this->finder->find($bean, $searchoperators, $start, $end, $orderby, $extraSQL);
     
    }
		
    
		/**
		 * Returns a plain and simple array filled with record data
		 * @param $type
		 * @param $start
		 * @param $end
		 * @param $orderby
		 * @return unknown_type
		 */
		public function listAll($type, $start=false, $end=false, $orderby="id ASC", $extraSQL = false) {
 
			$db = $this->db;
 
			$listSQL = $this->writer->getQuery("list",array(
				"type"=>$type,
				"start"=>$start,
				"end"=>$end,
				"orderby"=>$orderby,
				"extraSQL"=>$extraSQL
			));
			
			
			return $db->get( $listSQL );
 
		}
		

		/**
		 * Associates two beans
		 * @param $bean1
		 * @param $bean2
		 * @return unknown_type
		 */
		public function associate( RedBean_OODBBean $bean1, RedBean_OODBBean $bean2 ) { //@associate
                        return $this->association->link( $bean1, $bean2 );
		}

		/**
		 * Breaks the association between a pair of beans
		 * @param $bean1
		 * @param $bean2
		 * @return unknown_type
		 */
		public function unassociate(RedBean_OODBBean $bean1, RedBean_OODBBean $bean2) {
                    return $this->association->breakLink( $bean1, $bean2 );
		}

		/**
		 * Fetches all beans of type $targettype assoiciated with $bean
		 * @param $bean
		 * @param $targettype
		 * @return array $beans
		 */
		public function getAssoc(RedBean_OODBBean $bean, $targettype) {
                        return $this->association->get( $bean, $targettype );
		}


		/**
		 * Removes a bean from the database and breaks associations if required
		 * @param $bean
		 * @return unknown_type
		 */
		public function trash( RedBean_OODBBean $bean ) {
                        return $this->beanstore->trash( $bean );
                }
                
			
		/**
		 * Breaks all associations of a perticular bean $bean
		 * @param $bean
		 * @return unknown_type
		 */
		public function deleteAllAssoc( $bean ) {
                        return $this->association->deleteAllAssoc( $bean );
		}

		/**
		 * Breaks all associations of a perticular bean $bean
		 * @param $bean
		 * @return unknown_type
		 */
		public function deleteAllAssocType( $targettype, $bean ) {
                        return $this->association->deleteAllAssocType( $targettype, $bean );
                }
              

		/**
		 * Dispenses; creates a new OODB bean of type $type
		 * @param $type
		 * @return RedBean_OODBBean $bean
		 */
		public function dispense( $type="StandardBean" ) {

			$oBean = new RedBean_OODBBean();
			$oBean->type = $type;
			$oBean->id = 0;
			return $oBean;
		}


		/**
		 * Adds a child bean to a parent bean
		 * @param $parent
		 * @param $child
		 * @return unknown_type
		 */
		public function addChild( RedBean_OODBBean $parent, RedBean_OODBBean $child ) {
                        return $this->tree->add( $parent, $child );
                }

		/**
		 * Returns all child beans of parent bean $parent
		 * @param $parent
		 * @return array $beans
		 */
		public function getChildren( RedBean_OODBBean $parent ) {
                        return $this->tree->getChildren($parent);
                }
		
		/**
		 * Fetches the parent bean of child bean $child
		 * @param $child
		 * @return RedBean_OODBBean $parent
		 */
		public function getParent( RedBean_OODBBean $child ) {
                        return $this->tree->getParent($child);
                }

		/**
		 * Removes a child bean from a parent-child association
		 * @param $parent
		 * @param $child
		 * @return unknown_type
		 */
		public function removeChild(RedBean_OODBBean $parent, RedBean_OODBBean $child) {
                        return $this->tree->removeChild( $parent, $child );
		}
		
		/**
		 * Counts the associations between a type and a bean
		 * @param $type
		 * @param $bean
		 * @return integer $numberOfRelations
		 */
		public function numofRelated( $type, RedBean_OODBBean $bean ) {
			
			//get a database
			$db = $this->db;
			
			$t2 = $this->filter->table( $db->escape( $type ) );
						
			//is this bean valid?
			$this->checkBean( $bean );
			$t1 = $this->filter->table( $bean->type  );
			$tref = $this->filter->table( $db->escape( $bean->type ) );
			$id = intval( $bean->id );
						
			//infer the association table
			$tables = array();
			array_push( $tables, $t1 );
			array_push( $tables, $t2 );
			
			//sort the table names to make sure we only get one assoc table
			sort($tables);
			$assoctable = $db->escape( implode("_",$tables) );
			
			//get all tables
			$tables = $this->showTables();
			
			if ($tables && is_array($tables) && count($tables) > 0) {
				if (in_array( $t1, $tables ) && in_array($t2, $tables)){
					$sqlCountRelations = $this->writer->getQuery(
						"num_related", array(
							"assoctable"=>$assoctable,
							"t1"=>$t1,
							"id"=>$id
						)
					);
					
					return (int) $db->getCell( $sqlCountRelations );
				}
			}
			else {
				return 0;
			}
		}
		
		/**
		 * Accepts a comma separated list of class names and
		 * creates a default model for each classname mentioned in
		 * this list. Note that you should not gen() classes
		 * for which you already created a model (by inheriting
		 * from ReadBean_Decorator).
		 * @param string $classes
		 * @param string $prefix prefix for framework integration (optional, constant is used otherwise)
		 * @param string $suffix suffix for framework integration (optional, constant is used otherwise)
		 * @return unknown_type
		 */
		
		public function generate( $classes, $prefix = false, $suffix = false ) {
			return $this->classGenerator->generate($classes,$prefix,$suffix);
                }
			
		


		/**
		 * Changes the locktime, this time indicated how long
		 * a user can lock a bean in the database.
		 * @param $timeInSecs
		 * @return unknown_type
		 */
		public function setLockingTime( $timeInSecs ) {

			if (is_int($timeInSecs) && $timeInSecs >= 0) {
				$this->locktime = $timeInSecs;
			}
			else {
				throw new RedBean_Exception_InvalidArgument( "time must be integer >= 0" );
			}
		}

                public function getLockingTime() { return $this->locktime; }


		
		/**
		 * Cleans the entire redbean database, this will not affect
		 * tables that are not managed by redbean.
		 * @return unknown_type
		 */
		public function clean() {

			if ($this->frozen) {
				return false;
			}

			$db = $this->db;

			$tables = $db->getCol( $this->writer->getQuery("show_rtables") );

			foreach($tables as $key=>$table) {
				$tables[$key] = $this->writer->getEscape().$table.$this->writer->getEscape();
			}

			$sqlcleandatabase = $this->writer->getQuery("drop_tables",array(
				"tables"=>$tables
			));

			$db->exec( $sqlcleandatabase );

			$db->exec( $this->writer->getQuery("truncate_rtables") );
			$this->resetAll();
			return true;

		}
		
	
		/**
		 * Removes all tables from redbean that have
		 * no classes
		 * @return unknown_type
		 */
		public function removeUnused( ) {

			//oops, we are frozen, so no change..
			if ($this->frozen) {
				return false;
			}

                        return $this->gc->removeUnused( $this, $this->db, $this->writer );

			
		}
		/**
		 * Drops a specific column
		 * @param $table
		 * @param $property
		 * @return unknown_type
		 */
		public function dropColumn( $table, $property ) {
			
			//oops, we are frozen, so no change..
			if ($this->frozen) {
				return false;
			}

			//get a database
			$db = $this->db;
			
			$db->exec( $this->writer->getQuery("drop_column", array(
				"table"=>$table,
				"property"=>$property
			)) );
			
		}

		/**
	     * Removes all beans of a particular type
	     * @param $type
	     * @return nothing
	     */
	    public function trashAll($type) {
	        $this->db->exec( $this->writer->getQuery("drop_type",array("type"=>$this->filter->table($type))));
	    }

	  
		
		public static function gen($arg, $prefix = false, $suffix = false) {
			return self::getInstance()->generate($arg, $prefix, $suffix);
		}
	
		public static function keepInShape($gc = false ,$stdTable=false, $stdCol=false) {
			return self::getInstance()->optimizer->run($gc, $stdTable, $stdCol);
		}

                public function getInstOf( $className, $id=0 ) {
                    if (!class_exists($className)) throw new Exception("Class does not Exist");
                    $object = new $className($id);
                    return $object;
                }
}


