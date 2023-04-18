<?php
class ControllerFreshworksInfo extends Controller
{
	protected $totalSales;

	public function index() {
		$this->totalSales = 0;

		$json = array();
		$this->load->model('account/api');
		$this->response->addHeader('Content-Type: application/json');

		// Login with API Key
		if (!empty($this->request->post['username']) && !empty($this->request->post['key'])) {
			$api_info = $this->model_account_api->login($this->request->post['username'], $this->request->post['key']);
		} else {
			$api_info = [];
		}

		if (!$api_info) {
			$json['error'] = 'Incorrect API Key or Username';

			$this->response->setOutput(json_encode($json));
			return;
		}

		$this->load->model('account/customer');
		$this->load->model('freshworks/info');

		$data = ['customer_list' => [], 'order_list' => []];

		$customerEmail = $this->request->post['email'] ?:null;
		if (isset($this->request->post['order']) && $orderId = $this->request->post['order']) {
			$customerEmail = $this->getCustomerEmailFromOrder($orderId);
		}

		if (!$customerEmail) {
			$this->response->setOutput(json_encode($data));
			return;
		}

		$orderInfo = $this->getOrderInfo($customerEmail);
		$customerInfo = $this->getCustomerInfo($customerEmail);

		if (!$customerInfo && $orderInfo) {
			$customerInfo = $this->getCustomerInfoFromOrder($customerEmail);
		}

		$data['customer_list'] = $customerInfo;
		$data['order_list'] = $orderInfo;

		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));

	}

	/**
	 * @param int $orderId
	 * @return null|string
	 */
	protected function getCustomerEmailFromOrder($orderId)
	{
		$order = $this->model_freshworks_info->getOrder($orderId);
		if (!$order) {
			return null;
		}
		return $order['email'];
	}

	/**
	 * @param string $email
	 * @return array
	 */
	protected function getCustomerInfo($email)
	{
		$customer_info = $this->model_account_customer->getCustomerByEmail($email);
		if (!$customer_info) {
			return [];
		}

		// Customer Group
		$customerGroup = null;
		if (isset($customer_info['customer_group_id'])) {
			$this->load->model('account/customer_group');
			$customer_group_id = (int)$customer_info['customer_group_id'];
			$customer_group_info = $this->model_account_customer_group->getCustomerGroup($customer_group_id);
			$customerGroup = $customer_group_info['name'];
		}

		// Reward
		$reward = $this->model_account_customer->getRewardTotal($customer_info['customer_id']);
		if (!$reward) {
			$reward = 0;
		}

		// Address
		$addressList = $this->model_freshworks_info->getAddressListByCustomerId($customer_info['customer_id']);

		try {
			$address = reset($addressList);
			$country = $address['country'];
			foreach ($addressList as $key => $address) {
				$addressList[$key]['short_format'] = $this->formatAddress($address['short']);
				unset($addressList[$key]['short']);
				if ($address['address_id'] == $customer_info['address_id']) {
					$country = $address['country'];
				}
			}
		} catch (\Exception $e) {
			$country = '';
		}

		return [
			'name' => $customer_info['firstname'] . ' ' . $customer_info['lastname'],
			'email' => $customer_info['email'],
			'group' => $customerGroup,
			'reward' => $reward,
			'country' => $country,
			'created_at' => $customer_info['date_added'],
			'total_sales' => $this->totalSales,
			'address_list' => $addressList,
		];

	}

	/**
	 * @param string $customerEmail
	 * @return array
	 */
	protected function getCustomerInfoFromOrder($customerEmail)
	{
		$orderList = $this->model_freshworks_info->getOrdersByEmail($customerEmail);
		$order = $orderList[0];

		$billingAddress = [
			'firstname' => $order['payment_firstname'],
			'lastname' => $order['payment_lastname'],
			'street_1' => $order['payment_address_1'],
			'street_2' => $order['payment_address_2'],
			'city' => $order['payment_city'],
			'postcode' => $order['payment_postcode'],
			'country' => $order['payment_country'],
			'state' => $order['payment_zone']
		];

		$shippingAddress = [
			'firstname' => $order['shipping_firstname'],
			'lastname' => $order['shipping_lastname'],
			'street_1' => $order['shipping_address_1'],
			'street_2' => $order['shipping_address_2'],
			'city' => $order['shipping_city'],
			'postcode' => $order['shipping_postcode'],
			'country' => $order['shipping_country'],
			'state' => $order['shipping_zone']
		];
		$addressList = [];
		$billing = $this->formatAddress($billingAddress);
		if ($billing) {
			$shortAddress = $this->formatAddress([
				'firstname' => '',
				'lastname' => '',
				'street_2' => '',
				'postcode' => '',
				'state' => '',
				'street_1' => $billingAddress['street_1'],
				'city' => $billingAddress['city'],
				'country' => $billingAddress['country'],
			]);
			$addressList[] = ['address_id' => 0, 'short_format' => $shortAddress, 'country' => $billingAddress['country'], 'address_format' => $billing, 'default' => 0];
		}
		$shipping = $this->formatAddress($shippingAddress);
		if ($shipping) {
			$shortAddress = $this->formatAddress([
				'firstname' => '',
				'lastname' => '',
				'street_1' => $shippingAddress['street_1'],
				'street_2' => '',
				'city' => $shippingAddress['city'],
				'country' => $shippingAddress['country'],
				'postcode' => '',
				'state' => ''
			]);
			$addressList[] = ['address_id' => 0, 'short_format' => $shortAddress, 'country' => $shippingAddress['country'], 'address_format' => $shipping, 'default' => 0];
		}
		return [
			'name' => $order['firstname'] . ' ' . $order['lastname'],
			'email' => $order['email'],
			'group' => "Guest",
			'reward' => 0,
			'country' => $order['payment_country'],
			'total_sales' => $this->totalSales,
			'address_list' => $addressList
		];
	}

	/**
	 * @param string $email
	 * @return array
	 */
	protected function getOrderInfo($email)
	{
		$orderList = $this->model_freshworks_info->getOrdersByEmail($email);

		$this->load->model('account/order');
		$result = [];
		$orderStatusList = [1, 5, 15, 2, 3];
		$totalSales = 0;
		foreach ($orderList as $order) {
			$orderItemInfo = [];

			$totals = $this->model_account_order->getOrderTotals($order['order_id']);
			$products = $this->model_account_order->getOrderProducts($order['order_id']);
			$vouchers = $this->model_account_order->getOrderVouchers($order['order_id']);
			$totalsFormatted = [];
			foreach ($totals as $total) {
				$totalsFormatted[] = ['name' => $total['title'], 'value' => $this->currency->format($total['value'], $order['currency_code'])];
			}

			if (in_array($order['order_status_id'], $orderStatusList)) {
				$totalSales += $order['total'];
			}
			$this->totalSales = $this->currency->format($totalSales, $order['currency_code']);
			foreach ($products as $product) {
				$options = $this->model_account_order->getOrderOptions($order['order_id'], $product['order_product_id']);
				$optionList = [];
				foreach ($options as $option) {
					$optionList[] = ['name' => $option['name'], 'value' => $option['value']];
				}

				$orderItemInfo[] = [
					'name' => $product['name'],
					'sku' => $product['model'],
					'price' => $this->currency->format($product['price'], $order['currency_code']),
					'total' => $this->currency->format($product['total'], $order['currency_code']),
					'tax_rate' => (int)$product['tax'],
					'ordered_qty' => (int)$product['quantity'],
					'options' => $optionList
				];
			}

			foreach ($vouchers as $voucher) {
				$themeDescription = $this->model_freshworks_info->getVoucherThemeDescription($voucher['voucher_theme_id']);
				$optionList = [
					['name' => 'From Name', 'value' => $voucher['from_name']],
					['name' => 'From Email', 'value' => $voucher['from_email']],
					['name' => 'To Name', 'value' => $voucher['to_name']],
					['name' => 'To Email', 'value' => $voucher['to_email']],
					['name' => 'Message', 'value' => $voucher['message']],
					['name' => 'Theme Description', 'value' => $themeDescription['name']],
				];
				$orderItemInfo[] = [
					'name' => $voucher['description'],
					'sku' => 'voucher',
					'price' => $this->currency->format($voucher['amount'], $order['currency_code']),
					'total' => $this->currency->format($voucher['amount'], $order['currency_code']),
					'tax_rate' => 0,
					'ordered_qty' => 1,
					'options' => $optionList
				];
			}

			$billingAddress = [
				'firstname' => $order['payment_firstname'],
				'lastname' => $order['payment_lastname'],
				'street_1' => $order['payment_address_1'],
				'street_2' => $order['payment_address_2'],
				'city' => $order['payment_city'],
				'postcode' => $order['payment_postcode'],
				'country' => $order['payment_country'],
				'state' => $order['payment_zone']
			];

			$shippingAddress = [
				'firstname' => $order['shipping_firstname'],
				'lastname' => $order['shipping_lastname'],
				'street_1' => $order['shipping_address_1'],
				'street_2' => $order['shipping_address_2'],
				'city' => $order['shipping_city'],
				'postcode' => $order['shipping_postcode'],
				'country' => $order['shipping_country'],
				'state' => $order['shipping_zone']
			];

			$result[] = [
				'order_id' => $order['order_id'],
				'store' => $order['store_name'],
				'created_at' => $order['date_added'],
				'billing_address' => $this->formatAddress($billingAddress),
				'shipping_address' => $this->formatAddress($shippingAddress),
				'payment_method' => $order['payment_method'],
				'shipping_method' => $order['shipping_method'],
				'currency' => $order['currency_code'],
				'state' => $order['order_status_id'],
				'status' => $order['order_status'],
				'invoice_number' => $order['invoice_prefix'] . ' ' . $order['invoice_no'],
				'totals' => $totalsFormatted,
				'total' => $this->currency->format($order['total'], $order['currency_code']),
				'items' => $orderItemInfo
			];
		}

		return $result;
	}

	/**
	 * @param array $address
	 * @return string
	 */
	protected function formatAddress($address)
	{
		$result = [];
		if ($address['firstname'] || $address['lastname']) {
			$result[] = $address['firstname'] . ' ' . $address['lastname'];
		}
		$address['street_1'] && $result[] = $address['street_1'];
		$address['street_2'] && $result[] = $address['street_2'];
		$address['city'] && $result[] = $address['city'];
		$address['country'] && $result[] = $address['country'];
		$address['state'] && $result[] = $address['state'];
		$address['postcode'] && $result[] = $address['postcode'];

		return implode(', ', $result);
	}
}
