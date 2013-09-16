<?php

/** 
 * @package Aixada
 */ 

//ob_start(); // Starts FirePHP output buffering
require_once(__ROOT__ . 'php/lib/shop_cart_manager.php');

class fee_shop_item extends abstract_cart_row {


	protected $_iva_percent = 0;

	protected $_rev_tax_percent = 0;

	protected $_order_item_id = 0;


	public function __construct($id, $product_id, $quantity, $cart_id, $iva, $revtax, $order_item_id, $unit_price_stamp){
		$this->_id = $id;
		$this->_iva_percent = $iva;
		$this->_rev_tax_percent = $revtax;
		$this->_order_item_id = $order_item_id;

			
		$this->_product_id = $product_id;
		$this->_quantity = $quantity;
		$this->_cart_id = $cart_id;
		$this->_unit_price_stamp = $unit_price_stamp;

		//parent::__construct(0, 0, $product_id, $quantity, $cart_id);
	}

	//(cart_id, order_item_id, product_id, quantity, iva_percent, rev_tax_percent)
	public function commit_string()
	{
		return '('
				. $this->_id            . ','
				. $this->_cart_id 		. ','
				. $this->_order_item_id . ','
				. $this->_product_id 	. ','
				. $this->_quantity 		. ','
				. $this->_iva_percent	. ','
				. $this->_rev_tax_percent . ','
				. $this->_unit_price_stamp . ')';
	}
}

class shop_cart_fees_manager extends shop_cart_manager
{

	public function __construct($date)
	{
		$this->_id_string = 'shop';
		
		$this->_commit_rows_prefix =
		'replace into aixada_shop_item' .
		' (id, cart_id, order_item_id, product_id, quantity, iva_percent, rev_tax_percent, unit_price_stamp)' .
		' values ';		
		
		$this->_date = $date;
		$this->_cart_id = 'null';

	}
	

	
	private function get_totals_fees_by_provider($db, $date ) {
	
		// For all providers with feeds presents in the cart
		//       gets its total counters
		//
	
		$sql = "SELECT
		pder.id as provider_id,
    			 count(*) as quantity,
    			 sum(shop.quantity * shop.unit_price_stamp) as cost
    			FROM
				 aixada_shop_item shop
    			 inner join aixada_product prod on ( shop.product_id = prod.id)
    			 inner join aixada_provider pder on
    			   ( prod.provider_id = pder.id and pder.transport_fee_type_id!=0 )
    			WHERE
                 cart_id in (select id from aixada_cart where date_for_shop = :1q )  and
    			 prod.orderable_type_id != 3
	
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
				  cart.uf_id as uf_id,
				  cart.id as cart_id,
    			  count(*) as quantity,
    			  sum(quantity * unit_price_stamp) as cost
    			FROM
				  aixada_shop_item shop 
				  inner join aixada_cart cart on ( cart_id = cart.id and cart.date_for_shop = :1q )
				  inner join aixada_product prod on ( shop.product_id = prod.id)
    			  inner join aixada_provider pder on
    			  ( prod.provider_id = pder.id and pder.transport_fee_type_id!=0  )
    			WHERE
				  prod.orderable_type_id != 3
	
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
		$sqldel = "delete from aixada_shop_item
    			   where
    			      order_id in ( select id from aixada_order where date_for_order=:1q ) and
    			      product_id in ( select id from aixada_product where orderable_type_id = 3)";
    	$db->Execute( $sqldel , $date);
	    	 
	    	 
	}	
	
	protected function delete_cost_provider($db,$date,$provider)
	{
	
		// delete all transport fees
		$sqldel = "delete from aixada_shop_item
    			   where
    			      cart_id in ( select id from aixada_cart where date_for_shop=:1q ) and
    			      product_id in ( select id from aixada_product where orderable_type_id = 3 and provider_id=:2)";
		$db->Execute( $sqldel , $date, $provider);
		 
	}
	
	
	/**
	 * Transportation cost
	 */
	public function calculate_transport_fee($db, $prov) {
		
		//$this->delete_cost_provider($db,$this->_date,$prov);		
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
		    				$cost  = $provider_fee /  $total_info["cost"]   ;
		    				break;
		    				 
		    			case 2:
		    				$units = $uf_info["quantity"];
		    				$cost  = $provider_fee / $total_info["quantity"];
		 	    			break;
		    			
		    			default:
		    				;
		    				break;
		    		}
		    		
		    		// order item id
		    		$sql = "SELECT id,order_item_id from aixada_shop_item where product_id = :1 and cart_id = :2";
  				 	$rs = $db->Execute( $sql,$prov_info["product_id"], $uf_info["cart_id"]);
		            $row = $rs->fetch_array();
		            $cost_item = null;
		            $id = null;
		            if ( $row ) {
		            	$id = $row["id"];
		            	$cost_item = $row["order_item_id"];
		            }
		    				
					$this->_rows[] = new fee_shop_item(
							$id,
							$prov_info["product_id"],
							$units,
							$uf_info["cart_id"],
							$prov_info["iva"],
							$prov_info["rev_tax"],
							$cost_item,
							$cost
					 );
				}
			}
		}
		$this->_commit_rows();
	}
	
	public function XXcalculate_transport_units($db, $prov) {
	
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