<?php

$mg = new magento_alter;

//TO COPY A CUSTOMER, SIMPLY DO SOMETHING LIKE THIS:
$id_from_old_account = 1;
$mg->copy_customer($id_from_old_account);

//YOU'LL FIND THE ID FROM THE SOURCE ACCOUNT IN THE DATABASE IN customer_entity.entity_id
//THIS SCRIPT WILL COPY A CUSTOMER AND ALL HIS ORDERS - IT ASSUMES THAT THE PRODUCTS OF THE TWO STORES ARE THE SAME


class magento_alter
{
	private $pdo_source		= null;
	private $pdo_target		= null;
	
	public $from_id		= null;
	public $to_id		= null;
	
	function __construct()
	{

		include('config_db.php');

		//TURN OFF FOREIGN CONSTRAINTS
		$query = "set foreign_key_checks = 0";
		$this->pdo_source->query($query);		
		
	}
	
	function __destruct()
	{

		//TURN KEY RESTRAINTS BACK ON
		$query = "set foreign_key_checks = 1";
		$this->pdo_source->query($query);		
		
	}

	//COPY A CUSTOMER FROM THE SOURCE TO THE TARGET
	public function copy_customer( $cid )
	{
		
		//SHOULD FIRST CHECK THAT BOTH MAGENTO DATABASES ARE THE SAME
		
		//GET TABLES WITH A FOREIGN KEY RESTRAINT TO THE customer_entity TABLE
		$cust_restraint_tables	= $this->get_customer_restraint_tables($this->pdo_source);
		
		//GET TABLES WITH THE COLUMN customer_id THAT ARE NOT FOREIGN KEY RESTRAINT TABLES
		$cust_id_tables			= array_diff_assoc( $this->get_customer_id_tables($this->pdo_source), $cust_restraint_tables  );

		//ALL TABLES FOR CUSTOMER
		$tables = array_merge( $cust_restraint_tables, $cust_id_tables );

		//ELIMINATE TABLES FOR ORDERS
		$sales_tables			= $this->get_sales_restraint_tables( $this->pdo_source );
		
		$sales_tables['sales_flat_order'] = 'customer_id';
		
		$tables = array_diff_key( $tables, $sales_tables );
		
		//print_r($tables);
		//print_r($sales_tables);
		//exit;

		//GET A LIST OF ALL THE PRIMARY KEYS IN EACH TABLE
		$primary_keys			= $this->get_primary_keys($this->pdo_source);
		

		//FIND ALL TABLES WITH 2+ PRIMARY KEYS AND GET RID OF THEM
		//IF THE PROGRAM EXITED ON THIS LOOP, A ROW HAS MORE THAN 1 PRIMARY KEY AND THIS CLASS IS NOT SET UP TO HANDLE IT YET
		foreach ( $tables as $table_name=>$column_name )
		{

			if ( count( $primary_keys[$table_name] ) > 1 )
			{
				$result = $this->pdo_source->query('select count(*) from ' . $table_name . ' ')->fetchColumn();

				$rowsInTableForCid = $this->pdo_source->query('select count(*) from ' . $table_name . ' where ' . $tables[$table_name] . ' = ' . $cid)->fetchColumn();
				
				if ( $rowsInTableForCid > 0 )
				{
					echo $table_name . ' has ' . count($primary_keys[$table_name]) . ' primary keys and ' . $result . ' rows' . "\n";
					echo 'There are ' . $rowsInTableForCid . ' rows with the customer id as the value of the column ' . $tables[$table_name] ;
					echo 'exiting, line ' . __LINE__. "\n";exit;
				}

			}

		}
		
		//FIRST INSERT THE CUSTOMER ROW INTO THE customer_entity TABLE
		$query_source = "select * from customer_entity where entity_id = " . $cid . ";";
		foreach ( $this->pdo_source->query($query_source) as $row )
		{
			unset( $row['entity_id'] );
			
			$query_target = "insert into customer_entity (" . implode(',', array_keys($row)) . ") values (" . implode(',', array_fill(0, count($row), '?')) . ")";
			
			//echo $query_target . "\n";
			//print_r($row);
			//CREATE PARAMETERS FOR ->execute() CALL
			$params = array();
			foreach ( $row as $key=>$value)
			{
				$params[] = $value;
			}
			
			//COMMENT IN AFTER TESTING
			//*
			$st	= $this->pdo_target->prepare($query_target);
			$st->execute( $params );
			
			$target_cid = $this->pdo_target->lastInsertId();
			//*/
			//$target_cid = 13734;
			
			echo "Customer Inserted to target with entity_id = " . $target_cid . "\n";
			
			//print_r($row);exit;
		}
		//RETURN THE last_insert_id AND USE IT FOR THE OTHER TABLES
	

	
		//THESE ARE TABLES WE CAN WORK WITH
		foreach ( $tables as $table_name=>$column_name )
		{

			echo $table_name . " " . $column_name . "\n";
			//GET ROWS FROM SOURCE WHERE THE SOURCE customer id IS PRESENT
			$query_source = "select * from " . $table_name . " where " . $column_name . " = " . $cid . ";";
					
			foreach ( $this->pdo_source->query($query_source) as $row )
			{
				
				//print_r($row);
				
				//GET RID OF THE PRIMARY KEY
				unset( $row[$primary_keys[$table_name][0]] );
				//REPLACE FOREIGN RESTRAINT WITH THE NEW CLIENT ID
				$row[$column_name] = $target_cid;
				
				$query_target = "insert into " . $table_name . " (" . implode(',', array_keys($row)) . ") values (" . implode(',', array_fill(0, count($row), '?')) . ")";
				
				//echo $query_target . "\n";
				//print_r($row);
				//CREATE PARAMETERS FOR ->execute() CALL
				$params = array();
				foreach ( $row as $key=>$value)
				{
					$params[] = $value;
				}
				
				$st	= $this->pdo_target->prepare($query_target);
				$st->execute( $params );
			
				//exit;
				
			}

		}
		
		echo "DONE copying customer\n executing: \n \$mg->copy_sales(". $cid ." , " . $target_cid . ");\n";
		$this->copy_sales( $cid, $target_cid );
	
	}


	public function copy_sales( $old_cid, $new_cid )
	{

		$cid = $old_cid;

		//GET TABLES WITH A FOREIGN KEY RESTRAINT TO THE sales_flat_order TABLE
		$tables			= $this->get_sales_restraint_tables( $this->pdo_source );
		
		//$sales_tables['sales_flat_order'] = 'customer_id';
		//print_r($tables);

		//GET A LIST OF ALL THE PRIMARY KEYS IN EACH TABLE
		$primary_keys			= $this->get_primary_keys($this->pdo_source);
		
		//FIND ALL TABLES WITH 2+ PRIMARY KEYS AND GET RID OF THEM
		//IF THE PROGRAM EXITED ON THIS LOOP, A ROW HAS MORE THAN 1 PRIMARY KEY AND THIS CLASS IS NOT SET UP TO HANDLE IT YET
		foreach ( $tables as $table_name=>$column_name )
		{

			if ( count( $primary_keys[$table_name] ) > 1 )
			{
			
				$rowsInTableForCid = $this->pdo_source->query('select count(*) from ' . $table_name . ' where ' . $tables[$table_name] . ' = ' . $cid)->fetchColumn();
				
				if ( $rowsInTableForCid > 0 )
				{
					echo $table_name . ' has ' . count($primary_keys[$table_name]) . ' primary keys and ' . $result . ' rows' . "\n";
					echo 'There are ' . $rowsInTableForCid . ' rows with the customer id as the value of the column ' . $tables[$table_name] ;
					echo 'exiting, line ' . __LINE__. "\n";exit;
				}

			}

		}
		
		//FIND ALL ORDERS FOR THIS CUSTOMER
		$order_numbers = array();
		$query_source = "select entity_id from sales_flat_order where customer_id = " . $old_cid;
		foreach ( $this->pdo_source->query($query_source) as $row )
		{
			$order_numbers[] = $row['entity_id'];
		}

		foreach ( $order_numbers as $order_id )
		{

			//FIRST INSERT THE ORDER ROW INTO THE sales_flat_order TABLE
			$query_source = "select * from sales_flat_order where entity_id = " . $order_id . ";";
			
			foreach ( $this->pdo_source->query($query_source) as $row )
			{
				
				//UNSET THE PRIMARY KEY
				$source_order_id = $row['entity_id'];
				unset( $row['entity_id'] );
				unset( $row['increment_id'] );
				$row['customer_id'] = $new_cid;
				
				//GRAB AN increment_id
				$query_increment_id = "select max(increment_id)+1 from sales_flat_order";
				$increment_id = $this->pdo_target->query($query_increment_id)->fetchColumn();
				$row['increment_id'] = $increment_id;
				//echo $increment_id;exit;
						
				$query_target = "insert into sales_flat_order (" . implode(',', array_keys($row)) . ") values (" . implode(',', array_fill(0, count($row), '?')) . ")";
				
				//$query_target = "insert into sales_flat_order (" . implode(',', array_keys($row)) . ") values ('" . implode("', '", $row) . "')";
				
				//echo $query_target . "\n";
				//print_r($row);
				//exit;
				//CREATE PARAMETERS FOR ->execute() CALL
				$params = array();
				foreach ( $row as $key=>$value)
				{
					$params[] = $value;
				}
				
				//COMMENT IN AFTER TESTING
				//*
				$st	= $this->pdo_target->prepare($query_target);
				$st->execute( $params );
				
				//print_r ( $this->pdo_target->errorInfo() );
				
				$target_order_id = $this->pdo_target->lastInsertId();
				//*/
				//$target_order_id = 10699;
				
				echo "Order Inserted to target with entity_id = " . $target_order_id . "\n";
			
				//RETURN THE last_insert_id AND USE IT FOR THE OTHER TABLES

				echo $new_cid . "-target db customer_id " . $target_order_id . "-target database order id\n";

				//THESE ARE TABLES WE CAN WORK WITH
				foreach ( $tables as $table_name=>$column_name )
				{

					echo $table_name . " " . $column_name . "\n";
					//GET ROWS FROM SOURCE WHERE THE SOURCE order id IS PRESENT
					$query_source = "select * from " . $table_name . " where " . $column_name . " = " . $source_order_id . ";";
					//echo $query_source . "\n";
							
					foreach ( $this->pdo_source->query($query_source) as $row2 )
					{
						
						//print_r($row2);
						
						//GET RID OF THE PRIMARY KEY
						unset( $row2[$primary_keys[$table_name][0]] );
						//REPLACE FOREIGN RESTRAINT WITH THE NEW CLIENT ID
						$row2[$column_name] = $target_order_id;
						
						//print_r($row2);exit;
						
						$query_target = "insert into " . $table_name . " (" . implode(',', array_keys($row2)) . ") values (" . implode(',', array_fill(0, count($row2), '?')) . ")";
						
						//echo $query_target . "\n";
						//print_r($row2);
						//CREATE PARAMETERS FOR ->execute() CALL
						$params = array();
						foreach ( $row2 as $key=>$value)
						{
							$params[] = $value;
						}
						
						$st	= $this->pdo_target->prepare($query_target);
						$st->execute( $params );
					
						//exit;
						
					}

				}
				
			}

			echo "DONE WITH order id " . $target_order_id . "\n";
		
		}
		
		echo "DONE\n";

	}




	//FIND TABLES THAT HAVE FOREIGN RESTRAINT TO THE $table IN QUESTION
	public function get_restraint_tables( $table, $pdo )
	{
		
		$query = "select table_name, column_name, referenced_table_name, referenced_column_name from information_schema.key_column_usage where referenced_table_name = '" . $table . "';";
		//echo $query . "\n";

		$tables = array();
		foreach( $pdo->query($query) as $row )
		{
			$tables[$row['table_name']] = $row['column_name'];
			//echo $row['table_name'] . " " . $row['column_name'] . " " . $row['referenced_table_name'] . " " . $row['referenced_column_name'] . "\n";
			if ( 'entity_id' != $row['referenced_column_name'] || $table != $row['referenced_table_name'] )
			{
				echo 'Line: ' . __LINE__ . ' refers to ' . $row['referenced_table_name'] . '.' . $row['referenced_column_name'] . ' instead of ' . $table . '.entity_id' . "\n";die();
			}
		}
		return $tables;		
		
	}
	
	public function get_customer_restraint_tables( $pdo )
	{
		return $this->get_restraint_tables( 'customer_entity', $pdo );	
	}

	public function get_sales_restraint_tables( $pdo )
	{
		return $this->get_restraint_tables( 'sales_flat_order', $pdo );
	}


	public function get_customer_id_tables( $pdo )
	{

		//FIND ALL TABLES WITH THE COLUMN customer_id
		$query = "select table_name, column_name from information_schema.columns where column_name = 'customer_id'";
		$tables2 = array();
		foreach( $pdo->query($query) as $row )
		{
			$tables2[$row['table_name']] = $row['column_name'];
		}
		return $tables2;

	}
	
	public function get_primary_keys( $pdo )
	{

		$query = "select table_name, column_name from information_schema.key_column_usage where CONSTRAINT_NAME = 'PRIMARY'";
		$tables2 = array();
		foreach( $pdo->query($query) as $row )
		{
			$tables2[$row['table_name']][] = $row['column_name'];
		}
		return $tables2;
	}
	







	//v1 METHODS THAT MIGHT BE USEFUL SOMETIME

	public function model_alter( $tables )
	{

		foreach ( $tables as $table=>$column )
		{
			echo $table . "\n";
			$query	= "update " . $table . " set " . $column . " = ? where " . $column . " = ?";
			$stmt	= $this->pdo->prepare($query);
			$stmt->execute( array(  $this->to_id, $this->from_id  ) );
			echo $stmt->rowCount() . " rows affected\n";
			if( 00000 != $stmt->errorCode() )
			{
				echo $query . "\n";
				echo "  $this->to_id  $this->from_id \n";
				print_r( $stmt->errorInfo() );
			}
		}
		
	}
	
	public function model_delete( $tables )
	{

		foreach ( $tables as $table=>$column )
		{
			echo $table . "\n";
			$query	= "delete from " . $table . "  where " . $column . " = ?";
			$stmt	= $this->pdo->prepare($query);
			$stmt->execute( array(  $this->to_id  ) );
			echo $stmt->rowCount() . " rows affected\n";
			if( 00000 != $stmt->errorCode() )
			{
				echo $query . "\n";
				echo "  $this->to_id  $this->from_id \n";
				print_r( $stmt->errorInfo() );
			}
		}
		
	}


	public function change_cid( $from_id, $to_id )
	{
		
		$this->from_id	= $from_id;
		$this->to_id	= $to_id;
		
		echo "changing customer id from " . $from_id . " to " . $to_id . "\n";

		$tables		= $this->get_customer_restraint_tables();
		$tables2	= $this->get_customer_id_tables();

		$this->model_alter( $tables );
		$this->model_alter( $tables2 );

		//LASTLY, UPDATE THE entity_id COLUMN IN customer_entity
		$query = "update customer_entity set entity_id = ? where entity_id = ?";
		$stmt	= $this->pdo->prepare($query);
		$stmt->execute( array( $this->to_id, $this->from_id ) );
		echo $query."\n";
		//echo "  $this->from_id  $this->to_id \n";
		echo $stmt->rowCount() . " rows affected\n";	
		if( $stmt->errorCode() != 00000 )
		{
			print_r( $stmt->errorInfo() );
		}

	}

	public function delete_cid( $cid )
	{
	
		echo "changing customer id " . $cid . "\n";
	
		$this->to_id = $cid;

		$tables		= $this->get_customer_restraint_tables();
		$tables2	= $this->get_customer_id_tables();

		$this->model_delete( $tables );
		$this->model_delete( $tables2 );

		//LASTLY, DELETE THE entity_id COLUMN IN customer_entity
		$query = "delete from customer_entity where entity_id = ?";
		$stmt	= $this->pdo->prepare($query);
		$stmt->execute( array( $this->to_id ) );
		echo $query."\n";
		//echo "  $this->from_id  $this->to_id \n";
		echo $stmt->rowCount() . " rows affected\n";	
		if( $stmt->errorCode() != 00000 )
		{
			print_r( $stmt->errorInfo() );
		}
	}





	
	public function get_order_id_tables()
	{

		$tables2 = array();
		//FIND ALL TABLES WITH THE COLUMN customer_id
		$query = "select table_name, column_name from information_schema.columns where column_name = 'order_id'";
		$tables2 = array();
		foreach( $this->pdo->query($query) as $row )
		{
			$tables2[$row['table_name']] = $row['column_name'];
		}
		return $tables2;

	}



	public function change_sales_flat_order_id( $from_id, $to_id )
	{
		
		$this->from_id	= $from_id;
		$this->to_id	= $to_id;
		
		echo "changing customer id from " . $from_id . " to " . $to_id . "\n";

		$tables = $this->get_sales_restraint_tables();
		$tables2 = $this->get_order_id_tables();
		
		echo count($tables);
		print_r($tables);
		echo count($tables2);
		print_r($tables2);
		exit;

		exit;
		//print_r($tables);exit;
		$this->model_alter( $tables );
		//$this->model_alter( $tables2 );

		//LASTLY, UPDATE THE entity_id COLUMN IN sales_flat_order
		$query = "update customer_entity set entity_id = ? where entity_id = ?";
		$stmt	= $this->pdo->prepare($query);
		$stmt->execute( array( $this->to_id, $this->from_id ) );		

	}

}




