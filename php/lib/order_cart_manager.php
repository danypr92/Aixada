<?php

  /** 
   * @package Aixada
   */ 




//ob_start(); // Starts FirePHP output buffering
require_once(__ROOT__ . 'php/lib/abstract_cart_manager.php');


/**
 * The class that manages a row of a shopping cart for placing an order for a future day.
 *
 * @package Aixada
 * @subpackage Shop_and_Orders
 */
class order_item extends abstract_cart_row {
	
	
    public function commit_string() 
    {
        return '(null, '	//order_id is always null; set when order is send_off/closed
        	. $this->_cart_id . ","
            . "'" . $this->_date . "',"
            . $this->_uf_id . ','
            . $this->_product_id . ','
            . $this->_quantity . ','
            . $this->_unit_price_stamp 
            . ')';
    }
}


/**
 * The class that manages a shopping cart for placing an order for a future day.
 *
 * There can be at most one shopping cart for any given day. This is
 * why carts have only dates.
 *
 * @package Aixada
 * @subpackage Shop_and_Orders
 */
class order_cart_manager extends abstract_cart_manager {
		
	
	//which of the order items pertain to a closed order. 
	protected $_closed_orders = array(); 

	
	
    /**
     * Although aixada_order_item has a field order_id, this is set to null by default.
     * @param int $uf_id
     * @param date $date_for_order
     */
    public function __construct($uf_id, $date_for_order)
    {
        $this->_id_string = 'order';
        $this->_commit_rows_prefix = 
            'replace into aixada_order_item' .
            ' (order_id, favorite_cart_id, date_for_order, uf_id, product_id, quantity, unit_price_stamp)' .
            ' values ';
        parent::__construct($uf_id, $date_for_order); 
    }

	/**
	 * Overload function of _make_rows of abstract cart manager. 
	 * 
	 * @param array $arrQuant quantity of product bought
	 * @param array $arrProdId product id of item bought
	 * @param array $arrIva	iva in percent of product
	 * @param array $arrRevTax	rev tax percent of product
	 * @param array $arrOrderItemId	the id from aixada_order_item(id). not used in order
	 * @param array $arrCartId		the id of aixada_cart(id). If set, this indicates favorite cart
	 * @param array $arrPreOrder		true/false if item is preorder
	 */
    protected function _make_rows($arrQuant, $arrProdId, $arrIva, $arrRevTax, $arrOrderItemId, $cart_id, $last_saved, $arrPreOrder, $arrPrice)
    {
    	//set the cartid to null for most orders. order_items have cart_id only if bookmarked as "favori te" cart
    	$this->_cart_id = (isset($cart_id) && $cart_id>0)? $cart_id:'null';
    	
    	     	
    	$db = DBWrap::get_instance();
    	
    	//make sure we don't have an empty cart (when deleting all items from order)
    	if (count($arrProdId) > 0){
	    	//get already closed orders for the current date and this uf. 
	    	//closed orders cannot be update anymore
	    	$sql = "select
	    				oi.product_id
	    			from 
	    				aixada_order_item oi
	    			where
	    				oi.date_for_order ='". $this->_date."'
	    				and oi.product_id in (";
	    	
			    	foreach ($arrProdId as $id){
			    		$sql .= $id . ",";
					}		
			
				$sql = rtrim($sql, ",") .") and oi.uf_id=".$this->_uf_id." and oi.order_id > 0;";

			$rs = $db->Execute($sql);	
	       	
	   		while ($row = $rs->fetch_array()){
	    		array_push($this->_closed_orders, $row['product_id']); 
	    	}
	       	$db->free_next_results();
    	}	
    	
    	
        for ($i=0; $i < count($arrQuant); ++$i) {
            if ($arrPreOrder[$i] == 'false'){
            	
            	//if product id exists in closed orders, don't update it. 
            	$closed = array_search($arrProdId[$i], $this->_closed_orders);

            	if ($closed === false and  ! array_key_exists( $arrProdId[$i], $this->product_filter) ){
	                $this->_rows[] = new order_item($this->_date,
	                                                $this->_uf_id,
	                                                $arrProdId[$i], 
	                                                $arrQuant[$i],  
	                                                $this->_cart_id, 
	                                                $arrPrice[$i]);
	                
	                
	                
            	}
            } 
                                                
        }
    }
    

    /**
     *  function to create a new order 
     */
     
  protected function new_item( $prodId, $quant, $price, $item_id, $iva, $revTax ,$uf = null) {
  	        if ( is_null($uf) ) {
  	        	$uf = $this->_uf_id;
  	        }
        	return new order_item(
    			$this->_date,
    			$uf,
    			$prodId,
    			$quant,
    			$this->_cart_id,
    			$price
        	);    
    }
    

    /**
     * 
s rows in aixada_order_item for given uf and date. 
     * On every commit all order items are delete and then rewritten. 
     */
    protected function _delete_rows()
    {
    	$db = DBWrap::get_instance();
        
    	//only delete those order items which don't have an order_id yet. 
    	$sqldel = "delete from aixada_order_item 
    			   where 
    			      uf_id=:1q and order_id is null and 
    			      (date_for_order=:2q or date_for_order='1234-01-23')";	
    	$db->Execute( $sqldel , $this->_uf_id, $this->_date);	
    	
    	// delete all transport fees
    	$sqldel = "delete from aixada_order_item
    			   where
    			      order_id is null and
    			      (date_for_order=:1q or date_for_order='1234-01-23') and
    			      product_id in ( select id from aixada_product where orderable_type_id = 3)";
    	$db->Execute( $sqldel , $this->_date);
    	
    	
    }
    
    protected function _calculate_transport_cost($db)
    {
    	$this->_rows = array();
    	$this->calculate_transport_fee($db,$this->_date,$this->_uf_id);
    	$this->_commit_rows();
    }
    
    protected function filter_transport_products($db,$uf,$date)
    {
    	 
    	//get products witch are transport products
    	$sqltrans = "SELECT product_id
    			   from aixada_order_item
    			   where
    			     uf_id=:1q and
    			     order_id is null and
    			     (date_for_order=:2q or date_for_order='1234-01-23') and
    			     product_id in ( select id from aixada_product where orderable_type_id=3)";
    	 
    	$rs = $db->Execute( $sqltrans, $uf, $date);
    	$transport_prods = [];
    	while ( $row = $rs->fetch_array()) {
    		$transport_prods[$row["product_id"]] = 1;
    	}
    	 
    	return $transport_prods;
    }
    
    protected function get_totals_fees_by_provider($db, $date ) {
    
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
        	$providers = [];
        	while ( $row = $rs->fetch_array()) {
        	$providers[$row["provider_id"]] = $row;
    	}
        return $providers;
    
    }
    
    protected function get_uf_totals_fees_by_provider($db, $date) {
    
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
        $providers = [];
    	while ( $row = $rs->fetch_array()) {
    
    	if ( ! array_key_exists($row["provider_id"], $providers ) ) {
    			$providers[$row["provider_id"]] = [];
    	}
    	$providers[$row["provider_id"]][$row["uf_id"]] = $row;
    	}
    
        return $providers;
    
    }
    
    
    
    /**
     * Overloaded function to commit the cart to the database
     */
    protected function _postprocessing($arrQuant, $arrProdId, $arrIva, $arrRevTax, $arrOrderItemId, $cart_id, $arrPreOrder, $arrPrice)
    {
       
        // now store preorder items
       	$this->_rows = array();
        for ($i=0; $i < count($arrQuant); ++$i) {
            if ($arrPreOrder[$i] == 'true')
             	$this->_rows[] = new order_item('1234-01-23',
                                                $this->_uf_id,
                                                $arrProdId[$i], 
                                                $arrQuant[$i],  
                                                $this->_cart_id, 
                                                $arrPrice[$i]);
             
                                                
        }
        $this->_commit_rows();
    }

 

}



?>