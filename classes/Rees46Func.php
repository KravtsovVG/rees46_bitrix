<?php

class Rees46Func
{
	const BASE_URL = 'http://api.rees46.com';
	//const BASE_URL = 'http://paloalto.foxloc.com:8080';

	/**
	 * insert script tags for Rees46
	 */
	public static function includeJs()
	{
		global $USER;

		$shop_id = self::shopId();

		if ($shop_id === false) {
			return;
		}

		?>
			<script type="text/javascript" src="http://cdn.rees46.com/rees46_script.js"></script>
			<script type="text/javascript" src="<?= self::BASE_URL ?>/init_script.js"></script>
			<script type="text/javascript">
				$(document).ready(function(){
					REES46.init('<?= $shop_id ?>', <?= $USER->GetId() ?: 'undefined' ?>);
					var date = new Date(new Date().getTime() + 365*24*60*60*1000);
					document.cookie = 'rees46_session_id=' + REES46.ssid + '; path=/; expires='+date.toUTCString();
				});
			</script>
		<?php
	}

	/**
	 * Get current shop id from the settings
	 *
	 * @return string|false
	 */
	private static function shopId()
	{
		$shop_id = COption::GetOptionString(rees46recommender::MODULE_ID, 'shop_id', false);

		return empty($shop_id) ? false : $shop_id;
	}

	/**
	 * get item params for view or cart push
	 *
	 * @param $id
	 * @return array
	 */
	private static function getItemArray($id)
	{
		$libProduct = new CCatalogProduct();
		$item = $libProduct->GetByID($id);

		$return = array(
			'item_id' => intval($id),
		);

		if (!empty($item['PURCHASING_PRICE'])) {
			$return['price'] = $item['PURCHASING_PRICE'];
		}

		if (!empty($item['QUANTITY'])) {
			$return['is_available'] = $item['QUANTITY'] > 0 ? 1 : 0;
		}

		return $return;
	}

	/**
	 * get item params for view or cart push from basket id
	 *
	 * @param $id
	 * @return array|bool
	 */
	private static function getBasketArray($id)
	{
		$libBasket = new CSaleBasket();
		$item = $libBasket->GetByID($id);

		if ($item === false) {
			return false;
		}

		$return = array(
			'item_id' => $item['PRODUCT_ID'],
		);

		if (!empty($item['PRICE'])) {
			$return['price'] = $item['PRICE'];
		}

		if (!empty($item['CAN_BUY'])) {
			$return['is_available'] = $item['CAN_BUY'] === 'Y' ? 1 : 0;
		}

		return $return;
	}

	/**
	 * push data via javascript (insert corresponding script tag)
	 *
	 * @param $action
	 * @param $data
	 * @param $order_id
	 */
	private static function jsPushData($action, $data, $order_id = null)
	{
		?>
			<script type="application/javascript">
				$(function () {
					REES46.pushData('<?= $action ?>', <?= json_encode($data) ?> <?= $order_id !== null ? ', '. $order_id : '' ?>);
				});
			</script>
		<?php
	}

	/**
	 * push data via curl
	 *
	 * @param $action
	 * @param $data
	 * @param $order_id
	 */
	private static function restPushData($action, $data, $order_id = null)
	{
		global $USER;

		$shop_id = self::shopId();

		if ($shop_id === false) {
			return;
		}

		if (isset($_COOKIE['rees46_session_id'])) {
			$ssid = $_COOKIE['rees46_session_id'];
		} else {
			return;
		}

		$rees = new REES46(self::BASE_URL, $shop_id, $ssid, $USER->GetID());

		try {
			$rees->pushEvent($action, $data, $order_id);
		} catch (REES46Exception $e) {
			error_log($e->getMessage());
			// do nothing at the time
		} catch (Pest_Exception $e) {
			error_log($e->getMessage());
			// do nothing at the time
		}
	}

	/**
	 * push view event
	 *
	 * @param $item_id
	 */
	public static function view($item_id)
	{
		$item = self::getItemArray($item_id);

		self::jsPushData('view', $item);
	}

	/**
	 * push add to cart event
	 *
	 * @param $basket_id
	 */
	public static function cart($basket_id)
	{
		$item = self::getBasketArray($basket_id);
		self::restPushData('cart', new REES46PushItem($item['item_id'], $item));
	}

	/**
	 * push remove from cart event
	 *
	 * @param $basket_id
	 */
	public static function removeFromCart($basket_id)
	{
		$item = self::getBasketArray($basket_id);
		self::restPushData('remove_from_cart', $item['item_id'], $item);
	}

	public static function purchase($order_id)
	{
		$libBasket = new CSaleBasket();
		$list = $libBasket->GetList(array(), array('ORDER_ID' => $order_id));

		$items = array();

		while ($item = $list->Fetch()) {
			$pushItem = new REES46PushItem($item['PRODUCT_ID']);
			$pushItem->amount = $item['QUANTITY'];
			$items []= $pushItem;
		}

		ob_start();
		var_dump($list, $list->Fetch(), $items);
		file_put_contents('/tmp/order', ob_get_clean());

		self::restPushData('purchase', $items, $order_id);
	}
}