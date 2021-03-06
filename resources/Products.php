<?php

namespace Camerazon;

use Tonic\Resource,
    Tonic\Response,
    Tonic\ConditionException,
    sandeepshetty\shopify_api;

/**
 * Products resource
 * @uri /products
 */
class Products extends Resource {
	
	// local DB fields
	public static $products_fields = array('product_id', 'created_at', 'updated_at', 'product_type', 'vendor', 'handle', 'title', 'body_html');

	// basic product cache
	private static $_products = array();
	
	/**
	 * @method GET
	 * @provides application/json
	 */
	public function get() {
		
		// list all products
		$products = self::cached();

		// JSON response
		return new Response(Response::OK, json_encode(array_values($products)), array('content-type' => 'application/json'));
	}
	
	
	/**
	 * Post a new product and sync DB with Shopify
	 * @method GET
	 * @method POST
	 * @provides application/json
	 */
	public function post() {
		
		// accepts JSON 
		$data = json_decode($this->request->data, TRUE);
		
		// TODO: add product if supplied
		
		// sync database and Shopify
		$this->_sync();
		
		// get updated products from DB
		$products = self::cached();
		
		// return product list
		return new Response(Response::CREATED, json_encode(array_values($products)), array('content-type' => 'application/json'));
	}
	
	// sync DB and Shopify
	public function _sync() {
		
		$response = '';
		
		// get current products from DB
		$query = \App::$container['db']->query("SELECT * FROM products");
		$products = $query->fetchAll(\PDO::FETCH_ASSOC);
		
		// arrange them by product_id, get variants
		$db_products = array();
		foreach ($products as $product) {
			
			// get product variants, order by variant ID
			$query = \App::$container['db']->query("SELECT * FROM products_variants WHERE product_id = '".$product['product_id']."' ORDER BY variant_id ASC");
			$variants = $query->fetchAll(\PDO::FETCH_ASSOC);
			
			// arrange by variant_id
			$product['variants'] = array();
			foreach ($variants as $variant)
				$product['variants'][$variant['variant_id']] = $variant;
			
			// everything goes in db_products
			$db_products[$product['product_id']] = $product;
		}
		

		try {
			
			// shopify API client
			$shopify = \App::$container['shopify'];

			// get shopify products
			$products = $shopify('GET', '/admin/products.json', array(), $response_headers);
			
			// API call limit helpers
			// echo shopify_api\calls_made($response_headers); // 2
			// echo shopify_api\calls_left($response_headers); // 298
			// echo shopify_api\call_limit($response_headers); // 300
			
			// no products returned -- "not modified" response
			if (empty($products))
				$response = 'Error syncing products.';
			

		} catch (shopify_api\Exception $e) {
	
			$response = $e->getInfo();
	
		} catch (shopify_api\CurlException $e) {
	
			$response = $e->getMessage();
	
		}

		// error, early return
		if (! empty($response))
			return new Response(304, $response);


		// compare to DB products
		$inserts = array(); // new products to add
		$updates = array(); // products to update
		$variants = array(); // variants to check
		foreach ($products as &$product) {
			
			// shopify id, map to our DB columns
			$pid = $product['id'];
			$product['product_id'] = $pid;
			
			// we have this product, compare fields
			if (isset($db_products[$pid])) {
				foreach (self::$products_fields as $field) {
					
					// date format to unix timestamp
					$shopify_value = ($field == 'created_at' OR $field == 'updated_at') ? strtotime($product[$field]) : $product[$field];
					
					// fields don't match
					if ($shopify_value != $db_products[$pid][$field]) {
						$updates[$pid] = &$db_products[$pid];
						break;
					}
				}
				
			// we don't have this product, create it
			} else {
				
				// add to local DB
				$inserts[$pid] = &$product;
			}
			
			// handle updating variants
			if (! empty($product['variants']) AND is_array($product['variants']))
				$variants[$pid] = $product['variants'];
		}
		
		// update products
		foreach ($updates as $pid => &$product)
			self::_update($product);
		
		// add products
		foreach ($inserts as $pid => &$product)
			self::_update($product, TRUE);
		
		// sync variants
		$response .= Variants::_sync_variants($variants);
		
		// debug stuff
		$response .= \Paste\Pre::r(array_keys($updates), 'UPDATES').'<br><br>';
		$response .= \Paste\Pre::r(array_keys($inserts), 'INSERTS').'<br><br>';
		
		// return new Response(Response::OK, $response, array('content-type' => 'text/html'));

	}
	
	// update or insert a product in the DB
	public static function _update(&$product, $insert = FALSE) {
		
		// insert product
		if ($insert) {
			$keys = '';
			$values = '';
			foreach($product as $key => $val) {
				if (in_array($key, self::$products_fields)) {
					$keys .= "{$key}, ";
					$values .= ":{$key}, ";
				}
			}
			$query = "INSERT INTO products (".substr($keys, 0, -2).") VALUES (".substr($values, 0, -2).")";

		} else {

			// update product
			$set = '';
			foreach($product as $key => $val)
				if (in_array($key, self::$products_fields))
					$set .= "{$key} = :{$key}, ";
			$query = "UPDATE products SET ".substr($set, 0, -2)." WHERE product_id = '".$product['product_id']."' LIMIT 1";
			
		}

		// prepare update query
		$query = \App::$container['db']->prepare($query);
		foreach($product as $key => $val) {
			if (in_array($key, self::$products_fields)) {
				// format dates
				if ($key == 'created_at' OR $key == 'updated_at')
					$val = strtotime($val);
				// bind value
				$query->bindValue(":{$key}", $val);
			}
		}
		
		// execute query and update/insert product
		$query->execute();
		
	}
	
	// cache all products from DB with variants
	public static function cached($product_id = NULL) {
		
		// cache products
		if (empty(self::$_products)) {
			
			// retrieve full product details
			$query = \App::$container['db']->query("SELECT * FROM products");
			$products = $query->fetchAll(\PDO::FETCH_ASSOC);

			// get variants
			foreach ($products as &$product) {
				
				// get product variants, order by position
				$query = \App::$container['db']->query("SELECT * FROM products_variants WHERE product_id = '".$product['product_id']."' ORDER BY position ASC");
				$product['variants'] = $query->fetchAll(\PDO::FETCH_ASSOC);
				
				// store in cache
				self::$_products[$product['product_id']] = &$product;
				
			}
		}
		
		// return product if product_id is specified
		if (! empty($product_id)) {
			
			// return our cached product if we could find it
			return (isset(self::$_products[$product_id])) ? self::$_products[$product_id] : FALSE;
		}

		// otherwise return all products
		return self::$_products;
		
	}
	
}


/**
 * Single Product resource
 * @uri /products/([0-9]+)
 */
class Product extends Resource {
	
	/**
	 * @method GET
	 * @provides application/json
	 */
	public function get($product_id = NULL) {
		
		// no product id specified
		if (empty($product_id))
			return new Response(404, 'You must specify a product ID.');

		// get product data
		$query = \App::$container['db']->query("SELECT * FROM products WHERE product_id = '$product_id' LIMIT 1");
		$product = $query->fetch(\PDO::FETCH_ASSOC);

		// product not found
		if (empty($product))
			return new Response(404, 'Product ID not found.');
		
		// get variants for a product, order by position
		$query = \App::$container['db']->query("SELECT * FROM products_variants WHERE product_id = '$product_id' ORDER BY position");
		$variants = $query->fetchAll(\PDO::FETCH_ASSOC);
		
		// return in same format as Shopify API
		$product['variants'] = $variants;

		// JSON response
		return new Response(Response::OK, json_encode($product), array('content-type' => 'application/json'));
	}
}


/**
 * Variants resource
 * @uri /products/([0-9]+)/variants
 */
class ProductVariants extends Resource {
	
	/**
	 * @method GET
	 * @provides application/json
	 */
	public function get($product_id = NULL) {
		
		// no product id specified
		if (empty($product_id))
			return new Response(404, 'You must specify a product ID.');
		
		// get variants for a product, order by position
		$query = \App::$container['db']->query("SELECT * FROM products_variants WHERE product_id = '$product_id' ORDER BY position");
		$variants = $query->fetchAll(\PDO::FETCH_ASSOC);

		// JSON response
		return new Response(Response::OK, json_encode($variants), array('content-type' => 'application/json'));
	}

}
