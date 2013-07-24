<?php

/** 
 * @package Aixada
 */ 

//ob_start(); // Starts FirePHP output buffering
require_once(__ROOT__ . 'php/lib/order_cart_manager.php');

class order_cart_fees_manager extends order_cart_manager
{

	public function __construct($date)
	{
		$this->_id_string = 'order';
		$this->_commit_rows_prefix =
		'replace into aixada_order_item' .
		' (order_id, favorite_cart_id, date_for_order, uf_id, product_id, quantity, unit_price_stamp)' .
		' values ';
		$this->_date = $date;
		$this->_cart_id = 'null';

	}
	
	/**
	 * Gets Providers with transportation fees and info of the product associated to the fee
	 */
	private function get_providers_with_transportation_fees( $db) {
		// All providers with fees with any product in my cart
		 
		$sql = "select
			prov.id as provider_id ,
    		prov.transport_fee_type_id as fee_type,
			prod.id as product_id,
			prod.unit_price as cost,
    		prod.iva_percent_id as iva,
    		prod.rev_tax_type_id as rev_tax
		from
			aixada_provider prov inner join  aixada_product prod
    			   on ( prod.provider_id = prov.id  and prod.orderable_type_id = 3 and prov.transport_fee_type_id!=0)
	
		";
	
	
		$providers_with_fees = array();
		$rs = $db->Execute( $sql);
		while ( $row = $rs->fetch_array()) {
			$providers_with_fees [ $row["provider_id"]] = $row;
		}
	
		return $providers_with_fees;
	}
	
	private function get_totals_fees_by_provider($db, $date ) {
	
		// For all providers with feeds presents in the cart
		//       gets its total counters
		//
	
		$sql = "SELECT
		pder.id as provider_id,
    			 sum(quantity) as quantity,
    			 sum(quantity * unit_price_stamp) as cost
    			FROM
    			 aixada_order_item item
    			 inner join aixada_product prod on ( item.product_id = prod.id)
    			 inner join aixada_provider pder on
    			   ( prod.provider_id = pder.id and pder.transport_fee_type_id!=0 )
    			WHERE
    			 DATE(item.date_for_order) = :1q and prod.orderable_type_id != 3
	
    			group by 1
    			having sum(quantity) >0
    			order by 1
    			";
	
    	$rs = $db->Execute( $sql, $date);
	    	$providers = array();
	    	while ( $row = $rs->fetch_array()) {
	    	$providers[$row["provider_id"]] = $row;
		}
	    			return $providers;
	
	}
	
	private function get_uf_totals_fees_by_provider($db, $date) {
	
	// For all providers with feeds presents in the cart
		//       gets its cart total counters
		//
	
		$sql = "SELECT
		pder.id as provider_id,
		item.uf_id as uf_id,
    			  sum(quantity) as quantity,
    			  sum(quantity * unit_price_stamp) as cost
    			FROM
    			  aixada_order_item item
    			  inner join aixada_product prod on ( item.product_id = prod.id)
    			  inner join aixada_provider pder on
    			  ( prod.provider_id = pder.id and pder.transport_fee_type_id!=0  )
    			WHERE
    			  DATE(item.date_for_order) = :1q and prod.orderable_type_id != 3
	
    			group by 1,2
    			having sum(quantity) >0
    			order by 1,2
    			";
	
    	$rs = $db->Execute( $sql, $date);
    	$providers = array();
		while ( $row = $rs->fetch_array()) {
	
			if ( ! array_key_exists($row["provider_id"], $providers ) ) {
				$providers[$row["provider_id"]] = array();
    		}
			$providers[$row["provider_id"]][$row["uf_id"]] = $row;
		}
	
		return $providers;
		
	}

	protected function delete_rows($db,$date)
	{
					 
		// delete all transport fees
		$sqldel = "delete from aixada_order_item
    			   where
    			      order_id is null and
    			      (date_for_order=:1q or date_for_order='1234-01-23') and
    			      product_id in ( select id from aixada_product where orderable_type_id = 3)";
    	$db->Execute( $sqldel , $date);
	    	 
	    	 
	}	
	
	protected function filter_transport_products($db,$uf)
	{
	
		//get products witch are transport products
		$sqltrans = "SELECT product_id
    			   from aixada_order_item
    			   where
    			     uf_id=:1q and
    			     order_id is null and
    			     (date_for_order=:2q or date_for_order='1234-01-23') and
    			     product_id in ( select id from aixada_product where orderable_type_id=3)";
	
		$rs = $db->Execute( $sqltrans, $uf, $this->_date);
		$transport_prods = array();
		while ( $row = $rs->fetch_array()) {
			$transport_prods[$row["product_id"]] = 1;
		}
	
		return $transport_prods;
	}
	/**
	 * Transportation cost
	 */
	public function calculate_transport_fee($db, $prov) {
		
		$this->delete_rows($db,$this->_date);		
		$this->_rows = array();
		
		$providers = $this->get_providers_with_transportation_fees($db);
		$totals_by_provider = $this->get_totals_fees_by_provider($db, $this->_date);
		$uftotals_by_provider = $this->get_uf_totals_fees_by_provider($db, $this->_date);
		
		if (isset($providers[$prov]) ){
			
			$prov_info = $providers[$prov];
			$total_info = $totals_by_provider[$prov];

			$total_bought = $total_info["cost"];
			if ( $total_bought  > 0.01 ) {
			
				$provider_fee = $prov_info["cost"];
				
				foreach( $uftotals_by_provider[$prov] as $uf => $uf_info ) {
		
					
					 switch ($prov_info["fee_type"] ){
		    			case 1:
		    				$units = $uf_info["cost"];
		    				$cost  = $provider_fee * ($units /  $total_info["cost"]  ) ;
		    				break;
		    				 
		    			case 2:
		    				$units = $uf_info["quantity"];
		    				$cost  = $provider_fee * ( $units / $total_info["quantity"]);
		 	    			break;
		    			
		    			default:
		    				;
		    				break;
		    		}
									
					$this->_rows[] = $this->new_item(
							$prov_info["product_id"],
							$units,
							$cost,
							null,
							$prov_info["iva"],
							$prov_info["rev_tax"],
							$uf
					);
				}
			}
		}
		$this->_commit_rows();
	}
	
	
}

?>