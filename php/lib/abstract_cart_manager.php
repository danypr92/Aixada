<?php

  /** 
   * @package Aixada
   */ 



require_once(__ROOT__ . 'php/lib/exceptions.php');
require_once(__ROOT__ . 'local_config/config.php');
require_once(__ROOT__ . 'php/inc/database.php');

/**
 * This is the base class for rows of the tables.
 * @package Aixada
 * @subpackage Shop_and_Orders
 */

class abstract_cart_row {

    /**
	* @var int the unique id
	*/
    protected $_row_id = 0;

    /**
	* @var date the date when the cart is processed
	*/
    protected $_date = 0;

    /**
	* @var int the unique id of the uf buying or ordering
	*/
    protected $_uf_id = 0;

    /**
	* @var int the unique id of the product that the row stores
	*/
    protected $_product_id = 0;

    /**
	* @var float the quantity of the product
	*/
    protected $_quantity = 0;
    
    /**
     * @var int the unit price at the moment of ordering/shopping. need to keep track of price changes between 
     * orders or to reconstruct shopping in time (taking into account product price changes over time.  
     */
    protected $_unit_price_stamp = 0; 
    
    
    /**
     * Enter description here ...
     * @var unknown_type
     */
    protected $_cart_id = 0; 
    
    /**
     * The constructor takes the id of the containing cart, of the
     * product, the quantity and the price
     */
    public function __construct($date, $uf_id, $product_id, $quantity, $cart_id, $unit_price_stamp)
    {
        $this->_date = $date;
        $this->_uf_id = $uf_id;
        $this->_product_id = $product_id;
        $this->_quantity = $quantity;
        $this->_cart_id = $cart_id; 
        $this->_unit_price_stamp = $unit_price_stamp;
    }

    
    /**
     * return the product id
     */
    public function get_product_id()
    {
        return $this->_product_id;
    }

    /**
     * return the quantity
     */
    public function get_quantity()
    {
        return $this->_quantity;
    }

    /**
     * To be overwritten by shop/order cart managers. Constructs the commit string
     * for items. 
     */
    public function commit_string() {}
}



/**
 * The common part for all classes that manage a shopping
 * cart. Derived classes must  overwrite
 * @see _make_rows , @see _commit_cart
 *
 * @package Aixada
 * @subpackage Shop_and_Orders
 */
class abstract_cart_manager {
  
    /**
     * @var int stores the UF buying
     */
    protected $_uf_id;

    /**
     * @var date stores the current date 
     */
    protected $_date;
    
    /**
     * @var int the cart id
     */
    protected $_cart_id; 
    
    
    /**
     * Timestamp when cart was last saved in db
     * @var unknown_type
     */
    protected $_last_saved; 
    
    /**
     * @var array_of_rows 
     */
    protected $_rows;

    
    /**
     * @var boolean Has the cart been successfully committed?
     */
    protected $_commit_succeeded = false;

    /**
     * @var string the name of the database table that stores the items
     */
    protected $_item_table_name = '';

    /**
     * @var string this is 'shop', 'order'
     */
    protected $_id_string = '';

    /**
     * mysql commit prefix 
     * @var string
     */
    protected $_commit_rows_prefix = '';

 
       
    public function __construct($uf_id, $date=0)
    {
        if (!$uf_id) {
            throw new Exception('abstract_cart_manager::_construct: Need to specify uf_id');
            exit;
        }
        $this->_uf_id = $uf_id;
        $this->_date = $date;
        $this->_cart_id = 0;
        $this->_rows = array();
        $this->product_filter = array();
        $this->_item_table_name = 'aixada_' . $this->_id_string . '_item';
    }


    /**
     * Commits the cart content to the database, either aixada_shop_item or aixada_order_item. 
     * Returns the current cart_id.
     * 
     * @param array $arrQuant
     * @param array $arrProdId
     * @param array $arrIva
     * @param array $arrRevTax
     * @param array $arrOrderItemId
     * @param int $cart_id
     * @param array $arrPreOrder
     * @return int cart_id
     */
    public function commit($arrQuant, $arrProdId, $arrIva, $arrRevTax, $arrOrderItemId, $cart_id, $last_saved, $arrPreOrder, $arrPrice) 
    {
    	global $firephp;
    	$hasItems = true; 
    	
    	// are the input array sizes consistent?        
        if (  count($arrQuant)!= count($arrProdId) || count($arrProdId) != count($arrIva) || count($arrProdId) != count($arrRevTax)   )
            throw new Exception($this->_id_string . "_cart_manager::commit: mismatched array sizes: " . $arrQuant . ', ' . $arrProdId);
    	
		//if cart is empty, delete it
		if (count($arrQuant)==0) {
			$hasItems = false; 
		}

		    
        $this->_rows = array();
        
		$result = array(); 
		
        // now proceed to commit
        $db = DBWrap::get_instance();
        try {
            $db->Execute('START TRANSACTION');
            $this->product_filter = $this->filter_transport_products($db,$this->_uf_id,$this->_date);
            $this->_make_rows($arrQuant, $arrProdId, $arrIva, $arrRevTax, $arrOrderItemId, $cart_id, $last_saved, $arrPreOrder, $arrPrice);
            $this->_check_rows();
            $this->_delete_rows();
            if ($hasItems) {
            	$this->_commit_rows();
            	
            	$this->_calculate_transport_cost($db);
            	            	
            } else {
            	$this->_delete_cart();
            }
            
                       
            $this->_postprocessing($arrQuant, $arrProdId, $arrIva, $arrRevTax, $arrOrderItemId, $cart_id, $arrPreOrder, $arrPrice);
            $db->Execute('COMMIT');
        }
        catch (Exception $e) {
            $firephp->log($e);
            $this->_commit_succeeded = false;
            $db->Execute('ROLLBACK');
            throw($e);
        }
        $this->_commit_succeeded = true;    
        
        //and last_saved
        $result['cart_id'] = $this->_cart_id; 
        $result['ts_last_saved'] = $this->_last_saved; 
        
        return json_encode($result);	

    }

    /**
     * abstract function to create a new item
     */ 
    protected function new_item( $prodId, $quant, $price, $item_id, $iva, $revTax ) 
    {		
    }

    /**
     * abstract function to make the row classes
     */ 
    protected function _make_rows($arrQuant, $arrProdId, $arrIva, $arrRevTax, $arrOrderItemId, $cart_id, $last_saved, $arrPreOrder, $arrPrice)
    {
    }

    /**
     * abstract function to check the rows that were added
     */ 
    protected function _check_rows()
    {
    }

    /**
     * abstract function to delete the rows of a cart from an *_item table
     */ 
    protected function _delete_rows()
    {
    
    }
  
    /**
     * a cart can be deleted if all items have been removed before. 
     */
    protected function _delete_cart()
    {
    
    }
    
    
    /**
     * Commits the rows of the order to the database. 
     */
    protected function _commit_rows()
    {
        if (count($this->_rows) == 0) 
            return;
        
        $commitSQL = $this->_commit_rows_prefix;
        foreach ($this->_rows as $index => $row) {
            $commitSQL .= $row->commit_string() . ',';
        }
        $commitSQL = rtrim($commitSQL, ',') . ';';
         
        DBWrap::get_instance()->Execute($commitSQL);
    }

    /**
     * abstract function for postprocessing
     */ 
    protected function _postprocessing($arrQuant, $arrProdId, $arrIva, $arrRevTax, $arrOrderItemId, $cart_id, $arrPreOrder, $arrPrice)
    {
    }
    
    /**
     * Gets Providers with transportation fees and info of the product associated to the fee
     */
    protected function get_providers_with_transportation_fees( $db) {
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

    protected function filter_transport_products($db,$uf,$date)
    {
    	$transport_prods = array();
    	return $transport_prods;
    }
    
   
   protected function get_totals_fees_by_provider($db, $date ) {
   		$providers = array();
    	return $providers;
   }

   protected function get_uf_totals_fees_by_provider($db, $date) {
   		$providers = array();
   		return $providers;
   }

   protected function _calculate_transport_cost($db)
   {
   }
   

    /**
     * Transportation cost
     */
    protected function calculate_transport_fee($db, $date, $uf) {

    	$providers = $this->get_providers_with_transportation_fees($db);
    	$totals_by_provider = $this->get_totals_fees_by_provider($db, $date);
    	$uftotals_by_provider = $this->get_uf_totals_fees_by_provider($db, $date);
      
    	foreach ( $providers as $prov => $prov_info ) {
    		$total_info = $totals_by_provider[$prov];
    		
    		foreach( $uftotals_by_provider[$prov] as $uf => $uf_info ) {

	    		switch ($prov_info["fee_type"] ){
	    			case 1:
	    				;
	    				$units = $uf_info["cost"];
	    				$cost  = $prov_info["cost"] / ( $total_info["cost"]  ) ;
	    				break;
	    				 
	    			case 2:
	    				$units = $uf_info["quantity"];
	    				$cost  = $prov_info["cost"]  / ( $total_info["quantity"]  );
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
    
    

   
}
?>