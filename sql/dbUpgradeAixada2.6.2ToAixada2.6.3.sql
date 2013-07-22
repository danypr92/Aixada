/*******************************************
 * UPGRADE FILE 
 * to switch from Aixada v 2.6.1 to Aixada 2.6.1.fee
 * 
 * 
 */

/**
 * Aixada fee Type. How to distribute transportation costs
 **/
create table aixada_fee_type(
  id   				int				not null,
  description		varchar(255) 	not null, 
  primary key (id)
) ENGINE=InnoDB DEFAULT CHARACTER SET utf8;

insert into aixada_fee_type values ( 0, 'No Cost'); 
insert into aixada_fee_type values ( 1, 'Cost proportional'); 
insert into aixada_fee_type values ( 2, 'Number proportional'); 

insert into aixada_orderable_type values ( 3, 'transport');


/**
 * PROVIDER  has transport fees
 */
alter table 
      aixada_provider
      add  transport_fee_type_id  int  default null after active,
      add foreign key (transport_fee_type_id) references aixada_fee_type(id);



drop procedure if exists get_products_detail;
create procedure get_products_detail(	in the_provider_id int, 
										in the_category_id int, 
										in the_like varchar(255),
										in the_date date,
										in include_inactive boolean,
										in the_product_id int,
										in include_transport boolean)
begin
	
	declare today date default date(sysdate());
    declare wherec varchar(255);
    declare fromc varchar(255);
    declare fieldc varchar(255);
     
    
    /** show active products only or include inactive products as well **/
    set wherec = if(include_inactive=1,"","and p.active=1 and pv.active = 1");	
   
    
    /** no date provided we assume that we are shopping, i.e. all active products are shown stock + orderable **/
    if the_date = 0 then
    	set fieldc = "";
    	set fromc = "";
    	set wherec = 	concat(wherec, " and p.unit_measure_shop_id = u.id ");
    
    /** hack: date=-1 works to filter stock only products **/ 	
    elseif the_date = '1234-01-01' then 
    	set fieldc = "";
    	set fromc = "";
    	set wherec = concat(wherec, " and p.unit_measure_shop_id = u.id and (p.orderable_type_id = 1 or p.orderable_type_id = 4) ");
    
    /** otherwise search for products with orderable dates **/
    else 
    	set fieldc = concat(", datediff(po.closing_date, '",today,"') as time_left");
       	set fromc = 	"aixada_product_orderable_for_date po, ";
    	set wherec = 	concat(wherec, " and po.date_for_order = '",the_date,"' and po.product_id = p.id and p.unit_measure_order_id = u.id ");	
    end if;
     
    
    
    /** get a specific product **/
    if the_product_id > 0 then 
    	set wherec = concat(wherec, " and p.id = '", the_product_id, "' ");
    	
    /** get products by provider_id **/
    elseif the_provider_id > 0 then
		set wherec = concat(wherec, " and pv.id = '", the_provider_id, "' ");
    	
    /** get products by category_id **/
    elseif the_category_id > 0 then 
    	set fromc = concat(fromc, "aixada_product_category pc,");
    	set wherec = concat(wherec, " and pc.id = '", the_category_id, "' and p.category_id = pc.id ");
	
    /** search for product name **/
    elseif the_like != "" then
    	set wherec 	= concat(wherec, " and p.name LIKE '%", the_like,"%' ");
    	
    end if;
    
    if not include_transport then
    	set wherec 	= concat(wherec, " and p.orderable_type_id != 3 ");
    end if;
    
  
	set @q = concat("
	select
		p.*,
		round((p.unit_price * (1 + iva.percent/100) * (1 + t.rev_tax_percent/100)),2) as unit_price,
		p.unit_price as unit_price_netto, 
		pv.name as provider_name,	
		u.unit,
		iva.percent as iva_percent
		",fieldc,"
	from
		",fromc,"
		aixada_product p,
		aixada_provider pv, 
		aixada_rev_tax_type t,
		aixada_iva_type iva,
		aixada_unit_measure u 
	where 
		pv.id = p.provider_id	
		",wherec,"
		and p.iva_percent_id = iva.id 
		
	order by p.name asc, p.id asc;");
	
	prepare st from @q;
  	execute st;
  	deallocate prepare st;
  
end;




      