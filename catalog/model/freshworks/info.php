<?php
class ModelFreshworksInfo extends Model {
	/**
	 * @param int $id
	 * @return array
	 */
	public function getAddressListByCustomerId($id)
	{
		$address_data = [];
		$query = $this->db->query("SELECT `address_id` FROM `" . DB_PREFIX . "address` WHERE `customer_id` = '" . $id . "'");
		foreach ($query->rows as $result) {
			$address_info = $this->getAddress($result['address_id'], $id);

			if ($address_info) {
				array_push($address_data, $address_info);
			}
		}

		return $address_data;
	}

	/**
	 * @param string $email
	 * @return array
	 */
	public function getOrdersByEmail($email)
	{
		$query = $this->db->query("SELECT o.*, os.name as `order_status` FROM `" . DB_PREFIX . "order` o LEFT JOIN `" . DB_PREFIX . "order_status` os ON (o.`order_status_id` = os.`order_status_id`) WHERE o.`email` = '" . $email . "' ORDER BY o.`order_id` DESC ");
		return $query->rows;
	}

	/**
	 * @param int $voucher_theme_id
	 * @return array
	 */
	public function getVoucherThemeDescription($voucher_theme_id)
	{
		$query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "voucher_theme_description` WHERE `voucher_theme_id` = '" . (int)$voucher_theme_id . "'");
		return $query->row;
	}

	/**
	 * @param int $address_id
	 * @param int $customer_id
	 * @return array
	 */
	public function getAddress($address_id, $customer_id)
	{
		$address_query = $this->db->query("SELECT DISTINCT * FROM `" . DB_PREFIX . "address` WHERE `address_id` = '" . (int)$address_id . "' AND `customer_id` = '" . $customer_id . "'");
		if ($address_query->num_rows) {
			$this->load->model('localisation/country');
			$country_info = $this->model_localisation_country->getCountry($address_query->row['country_id']);
			if ($country_info) {
				$country = $country_info['name'];
				$address_format = $country_info['address_format'];
			} else {
				$country = '';
				$address_format = '';
			}

			$this->load->model('localisation/zone');

			$zone_info = $this->model_localisation_zone->getZone($address_query->row['zone_id']);
			if ($zone_info) {
				$zone = $zone_info['name'];
				$zone_code = $zone_info['code'];
			} else {
				$zone = '';
				$zone_code = '';
			}

			$find = [
				'{firstname}',
				'{lastname}',
				'{company}',
				'{address_1}',
				'{address_2}',
				'{city}',
				'{postcode}',
				'{zone}',
				'{zone_code}',
				'{country}'
			];

			$replace = [
				'firstname' => $address_query->row['firstname'],
				'lastname'  => $address_query->row['lastname'],
				'company'   => $address_query->row['company'],
				'address_1' => $address_query->row['address_1'],
				'address_2' => $address_query->row['address_2'],
				'city'      => $address_query->row['city'],
				'postcode'  => $address_query->row['postcode'],
				'zone'      => $zone,
				'zone_code' => $zone_code,
				'country'   => $country
			];

			$address_format = str_replace(["\r\n", "\r", "\n"], '<br/>', preg_replace(["/\s\s+/", "/\r\r+/", "/\n\n+/"], '<br/>', trim(str_replace($find, $replace, $address_format))));
			return [
				'address_id'     => $address_query->row['address_id'],
				'short' 		 => [
					'firstname' => '',
					'lastname' => '',
					'street_2' => '',
					'postcode' => '',
					'state' => '',
					'street_1' => $address_query->row['address_1'],
					'city' => $address_query->row['city'],
					'country' => $country
				],
				'country'        => $country,
				'address_format' => $address_format
			];
		} else {
			return [];
		}
	}

	/**
	 * @param int $orderId
	 * @return array|bool
	 */
	public function getOrder($orderId) {
		$order_query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order` WHERE `order_id` = '" . (int)$orderId . "'");

		if ($order_query->num_rows) {
			return $order_query->row;
		} else {
			return false;
		}
	}
}
