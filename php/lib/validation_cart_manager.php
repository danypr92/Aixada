<?php

/** 
 * @package Aixada
 */ 



require_once(__ROOT__.'php/lib/shop_cart_manager.php');

/**
 * The class to validate a cart
 *
 * @package Aixada
 * @subpackage Validation
 */
class validation_cart_manager extends shop_cart_manager {

  /**
   * @var int stores the UF in charge of the register
   */
  private $_op_id;

  /**
   * Constructor
   */
  public function __construct($op_id, $uf_id, $date_for_shop)
  {
    $this->_op_id = $op_id;
    parent::__construct($uf_id, $date_for_shop);
  }  

   

  /**
   * Overloaded function to commit the cart to the database
   */
 protected function _postprocessing($arrQuant, $arrProdId, $arrIva, $arrRevTax, $arrOrderItemId, $cart_id, $arrPreOrder, $arrPrice)
  {
    //do_stored_query('deduct_stock_and_pay', $cart_id);
    do_stored_query('validate_shop_cart', $cart_id, $this->_op_id);
  }
  

  protected function _delete_rows()
  {
  	$db = DBWrap::get_instance();
  	
  	
  	$db->Execute('DELETE FROM aixada_shop_item WHERE cart_id=:1q', $this->_cart_id);
   			 
	// delete all transport fees
  	$sqldel = "delete from aixada_shop_item
    			   where
    			      (select date_for_shop from aixada_cart where aixada_cart.id=cart_id)=:1q and
    			      product_id in ( select id from aixada_product where orderable_type_id = 3)";
    $db->Execute( $sqldel , $this->_date);  	 
  }
  
  protected function filter_transport_products($db,$uf,$date)
  {
  	// get transport costs to recalculate
  
  	$sqltrans = "SELECT product_id
    			   from aixada_shop_item
    			   where
    			     (select uf_id from aixada_cart where aixada_cart.id=cart_id)=:1q and
    			     (select date_for_shop from aixada_cart where aixada_cart.id=cart_id)=:2q and
    			     product_id in ( select id from aixada_product where orderable_type_id=3)";
  
  	$rs = $db->Execute( $sqltrans, $uf, $date);
  	$transport_prods = array();
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
    			 aixada_shop_item item
    			 inner join aixada_product prod on ( item.product_id = prod.id)
    			 inner join aixada_provider pder on
    			   ( prod.provider_id = pder.id and pder.transport_fee_type_id!=0 )
    			WHERE
    			 (select date_for_shop from aixada_cart where aixada_cart.id=cart_id) = :1q and prod.orderable_type_id != 3
  
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
    			  aixada_shop_item item
    			  inner join aixada_product prod on ( item.product_id = prod.id)
    			  inner join aixada_provider pder on
    			  ( prod.provider_id = pder.id and pder.transport_fee_type_id!=0  )
    			WHERE
    			  (select date_for_shop from aixada_cart where aixada_cart.id=cart_id) = :1q and prod.orderable_type_id != 3
 
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
 
}

?>