<?php

class WC_ROBOKASSA extends WC_Payment_Gateway {
	var $outsumcurrency = '';
	var $lang;
	var $liveurl = 'https://auth.robokassa.ru/Merchant/Index.aspx';
	var $only_bankcard = 'no';
	var $testmode = 'no';
	var $debug = 'no';
	private $robokassa_merchant;
	private $robokassa_key1;
	private $robokassa_key2;
	private $instructions;
	private $log;

	public function __construct() {
		$woocommerce_currency = get_option( 'woocommerce_currency' );
		if ( in_array( $woocommerce_currency, array( 'EUR', 'USD' ) ) ) {
			$this->outsumcurrency = $woocommerce_currency;
		}
		$plugin_dir = plugin_dir_url( __FILE__ );

		global $woocommerce;

		$this->id         = 'robokassa';
		$this->icon       = apply_filters( 'woocommerce_robokassa_icon', '' . $plugin_dir . 'robokassa.png' );
		$this->has_fields = false;

		// Load the settings
		$this->init_form_fields();
		$this->init_settings();

		// Define user set variables
		$this->method_title       = __( 'Robokassa', 'woocommerce' );
		$this->title              = $this->get_option( 'title' );
		$this->only_bankcard      = $this->get_option( 'only_bankcard' );
		$this->robokassa_merchant = $this->get_option( 'robokassa_merchant' );
		$this->robokassa_key1     = $this->get_option( 'robokassa_key1' );
		$this->robokassa_key2     = $this->get_option( 'robokassa_key2' );
		$this->testmode           = $this->get_option( 'testmode' );
		$this->lang               = $this->get_option( 'lang', 'ru' );
		$this->debug              = $this->get_option( 'debug' );
		$this->description        = $this->get_option( 'description' );
		$this->instructions       = $this->get_option( 'instructions' );

		// Logs
		if ( $this->debug == 'yes' ) {
			if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '<' ) ) {
				$this->log = $woocommerce->logger();
			} else {
				$this->log = new WC_Logger();
			}
		}

		// Actions
		add_action( 'valid-robokassa-standard-ipn-request', array( $this, 'successful_request' ) );
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );

		// Save options
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id,
			array( $this, 'process_admin_options' ) );

		// Payment listener/API hook
		add_action( 'woocommerce_api_wc_' . $this->id, array( $this, 'check_ipn_response' ) );

		if ( ! $this->is_valid_for_use() ) {
			$this->enabled = false;
		}
	}

	/**
	 * Initialise Gateway Settings Form Fields
	 *
	 * @access public
	 * @return void
	 */
	function init_form_fields() {
		$debug = __( 'Включить логирование', 'robokassa-payment-gateway-saphali' )
		         . ' (<code>woocommerce/logs/' . $this->id . '.txt</code>)';
		if ( ! version_compare( WOOCOMMERCE_VERSION, '2.0', '<' ) ) {
			if ( version_compare( WOOCOMMERCE_VERSION, '2.2.0', '<' ) ) {
				$debug = str_replace( $this->id, $this->id . '-' . sanitize_file_name( wp_hash( $this->id ) ), $debug );
			} elseif ( function_exists( 'wc_get_log_file_path' ) ) {
				$debug = str_replace( 'woocommerce/logs/' . $this->id . '.txt',
					'<a href="/wp-admin/admin.php?page=wc-status&tab=logs&log_file=' . $this->id . '-'
					. sanitize_file_name( wp_hash( $this->id ) ) . '-log" target="_blank">'
					. wc_get_log_file_path( $this->id ) . '</a>', $debug );
			}
		}
		$this->form_fields = array(
			'enabled'            => array(
				'title'   => __( 'Включить/Выключить', 'robokassa-payment-gateway-saphali' ),
				'type'    => 'checkbox',
				'label'   => __( 'Включен', 'robokassa-payment-gateway-saphali' ),
				'default' => 'yes'
			),
			'title'              => array(
				'title'       => __( 'Название', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'text',
				'description' => __( 'Это название, которое пользователь видит во время проверки.',
					'robokassa-payment-gateway-saphali' ),
				'default'     => __( 'ROBOKASSA', 'robokassa-payment-gateway-saphali' )
			),
			'only_bankcard'      => array(
				'title'       => __( 'Принимать только банковские карты', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'checkbox',
				'label'       => __( 'Включен', 'robokassa-payment-gateway-saphali' ),
				'description' => __( 'Пользователь будет переадресован на форму оплаты банковскими картами. Но сможет вернуться и выбрать другой тип оплаты.',
					'robokassa-payment-gateway-saphali' ),
				'default'     => 'no'
			),
			'robokassa_merchant' => array(
				'title'       => __( 'Идентификатор магазина', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'text',
				'description' => __( 'Пожалуйста введите идентификатор магазина',
					'robokassa-payment-gateway-saphali' ),
				'default'     => 'demo'
			),
			'robokassa_key1'     => array(
				'title'       => __( 'Пароль #1', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'password',
				'description' => __( 'Пожалуйста введите пароль №1.', 'robokassa-payment-gateway-saphali' ),
				'default'     => ''
			),
			'robokassa_key2'     => array(
				'title'       => __( 'Пароль #2', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'password',
				'description' => __( 'Пожалуйста введите пароль №2.', 'robokassa-payment-gateway-saphali' ),
				'default'     => ''
			),
			'testmode'           => array(
				'title'       => __( 'Тест режим', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'checkbox',
				'label'       => __( 'Включен', 'robokassa-payment-gateway-saphali' ),
				'description' => __( 'В этом режиме плата за товар не снимается.',
					'robokassa-payment-gateway-saphali' ),
				'default'     => 'no'
			),
			'debug'              => array(
				'title'   => __( 'Debug', 'robokassa-payment-gateway-saphali' ),
				'type'    => 'checkbox',
				'label'   => $debug,
				'default' => 'no'
			),
			'description'        => array(
				'title'       => __( 'Description', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'textarea',
				'description' => __( 'Описанием метода оплаты которое клиент будет видеть на вашем сайте.',
					'robokassa-payment-gateway-saphali' ),
				'default'     => 'Оплата с помощью robokassa.'
			),
			'instructions'       => array(
				'title'       => __( 'Instructions', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'textarea',
				'description' => __( 'Инструкции, которые будут добавлены на страницу благодарностей.',
					'robokassa-payment-gateway-saphali' ),
				'default'     => 'Оплата с помощью robokassa.'
			),
			'lang'               => array(
				'title'       => __( 'Язык общения с клиентом', 'robokassa-payment-gateway-saphali' ),
				'type'        => 'select',
				'options'     => array(
					""   => 'Выбрать',
					"ru" => "Русский",
					"en" => "English"
				),
				'description' => __( 'Вы определяете изначально сами, на каком языке интерфейс ROBOKASSA должен отображаться для клиента',
					'robokassa-payment-gateway-saphali' ),
				'default'     => 'ru'
			)
		);
	}

	/**
	 * Check if this gateway is enabled and available in the user's country
	 */
	function is_valid_for_use() {
		if ( ! in_array( get_option( 'woocommerce_currency' ), array( 'RUB', 'EUR', 'USD' ) ) ) {
			return false;
		}

		return true;
	} // End admin_options()

	static function valid_order_statuses_for_payment( $statuses, $order ) {
		if ( $order->payment_method != 'robokassa' ) {
			return $statuses;
		}
		$name         = 'woocommerce_payment_status_action_pay_button_controller';
		$option_value = get_option( 'woocommerce_payment_status_action_pay_button_controller', array() );
		if ( ! is_array( $option_value ) ) {
			$option_value = array( 'pending', 'failed' );
		}
		if ( ! in_array( 'pending', $option_value ) ) {
			$option_value[] = 'pending';
		}

		return $option_value;
	}

	/**
	 * Admin Panel Options
	 * - Options for bits like 'title' and availability on a country-by-country basis
	 *
	 * @since 0.1
	 **/
	public function admin_options() {
		?>
        <h3><?php _e( 'ROBOKASSA', 'robokassa-payment-gateway-saphali' ); ?></h3>
        <p><?php _e( 'Настройка приема электронных платежей через Merchant ROBOKASSA.',
				'robokassa-payment-gateway-saphali' ); ?></p>

        <div class="notice notice-info">
            <p>Настройки магазина на сайте <a href="https://partner.robokassa.ru" target="_blank">Robokassa</a>:</p>
            <ul>
                <li>Алгоритм расчета хеша: <b>SHA256</b></li>
                <li>Метод отсылки данных: <b>POST</b></li>
                <li>Result URL: <b><?= home_url(); ?>/?wc-api=wc_robokassa&robokassa=result</b></li>
                <li>Success URL: <b><?= home_url(); ?>/?wc-api=wc_robokassa&robokassa=success</b></li>
                <li>Fail URL: <b><?= home_url(); ?>/?wc-api=wc_robokassa&robokassa=fail</b></li>
            </ul>
        </div>

		<?php if ( $this->is_valid_for_use() ) : ?>

            <table class="form-table">

				<?php
				// Generate the HTML For the settings form.
				$this->generate_settings_html();
				?>
            </table><!--/.form-table-->

		<?php else : ?>
            <div class="inline error"><p><strong><?php _e( 'Шлюз отключен',
							'robokassa-payment-gateway-saphali' ); ?></strong>: <?php _e( 'ROBOKASSA не поддерживает валюты Вашего магазина.',
						'robokassa-payment-gateway-saphali' ); ?></p></div>
			<?php
		endif;

	}

	/**
	 * There are no payment fields for sprypay, but we want to show the description if set.
	 **/
	function payment_fields() {
		if ( $this->description ) {
			echo wpautop( wptexturize( $this->description ) );
		}
	}

	/**
	 * Process the payment and return the result
	 *
	 * @param int $order_id
	 *
	 * @return array
	 */
	function process_payment( $order_id ) {
		$order = new WC_Order( $order_id );
		if ( ! version_compare( WOOCOMMERCE_VERSION, '2.1.0', '<' ) ) {
			return array(
				'result'   => 'success',
				'redirect' => $order->get_checkout_payment_url( true )
			);
		}

		return array(
			'result'   => 'success',
			'redirect' => add_query_arg( 'order-pay', $order_id,
				add_query_arg( 'key', $order->order_key, get_permalink( wc_get_page_id( 'pay' ) ) ) )
		);
	}

	/**
	 * receipt_page
	 *
	 * @param $order_id
	 */
	function receipt_page( $order_id ) {
		echo '<p>' . __( 'Для оплаты заказа нажмите кнопку ОПЛАТИТЬ.', 'robokassa-payment-gateway-saphali' ) . '</p>';
		echo $this->generate_form( $order_id );
		do_action( 'woocommerce_view_order', $order_id );
	}

	/**
	 * Generate the dibs button link
	 *
	 * Docs:
	 *
	 * @param $order_id
	 *
	 * @return string
	 */
	public function generate_form( $order_id ) {
		$order      = new WC_Order( $order_id );
		$action_adr = $this->liveurl;

		$receipt = $this->get_order_receipt( $order_id );
		$this->log_data( $receipt );

		$out_summ = (float) $order->get_total();
		if ( empty( $this->outsumcurrency ) ) {
			$crc = $this->robokassa_merchant . ':' . $out_summ . ':' . $order_id . ':' . urlencode( $receipt ) . ':'
			       . $this->robokassa_key1;
		} else {
			$crc = $this->robokassa_merchant . ':' . $out_summ . ':' . $order_id . ':' . $this->outsumcurrency . ':'
			       . $this->robokassa_key1;
		}

		$args = array(
			// Merchant
			'MrchLogin'      => $this->robokassa_merchant,
			'OutSum'         => $out_summ,
			'InvId'          => $order_id,
			'InvDesc'        => $this->get_order_description( $order_id ),
			'Receipt'        => urlencode( $receipt ),
			'SignatureValue' => hash( 'sha256', $crc ),
			'Culture'        => $this->lang,
			'Encoding'       => 'utf-8',
		);
		$this->log_data( $args );

		if ( $this->only_bankcard == 'yes' ) {
			$args['IncCurrLabel'] = 'BankCard';
		}
		if ( ! empty( $order->get_billing_email() ) ) {
			$args['Email'] = $order->get_billing_email();
		}
		if ( $this->testmode == 'yes' ) {
			$args['IsTest'] = 1;
		}
		if ( ! empty( $this->outsumcurrency ) ) {
			$args['OutSumCurrency'] = $this->outsumcurrency;
		}
		$args = apply_filters( 'woocommerce_robokassa_args', $args );

		$args_array = array();

		foreach ( $args as $key => $value ) {
			$args_array[] =
				'<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '" />';
		}

		return
			'<form action="' . esc_url( $action_adr ) . '" method="POST" id="robokassa_payment_form">' . "\n" .
			implode( "\n", $args_array ) .
			'<input type="submit" class="fusion-button button-default fusion-button-default-size button alt" id="submit_robokassa_payment_form" value="'
			. __( 'Оплатить',
				'robokassa-payment-gateway-saphali' )
			. '" /> <a class="fusion-button button-default fusion-button-default-size button-red button cancel" href="'
			. $order->get_cancel_order_url() . '">' . __( 'Вернуться в корзину',
				'robokassa-payment-gateway-saphali' ) . '</a>' . "\n" .
			'</form>';
	}

	function get_order_receipt( $order_id, $max_length = 100 ) {
		/**
		 * Return JSON string with order receipt
		 *
		 * {
		 * "sno": "osn",
		 * "items": [
		 * {
		 * "name": "Название товара 1",
		 * "quantity": 1.0,
		 * "sum": 100.0,
		 * "tax": "vat10"
		 * },
		 * {
		 * "name": "Название товара 2",
		 * "quantity": 3,
		 * "sum": 450,
		 * "tax": "vat118"
		 * }
		 * ]
		 * }
		 *
		 * sno
		 * «osn» – общая СН;
		 * «usn_income» – упрощенная СН (доходы);
		 * «usn_income_outcome» – упрощенная СН (доходы минус расходы);
		 * «envd» – единый налог на вмененный доход;
		 * «esn» – единый сельскохозяйственный налог;
		 * «patent» – патентная СН.
		 *
		 * tax
		 * «none» – без НДС;
		 * «vat0» – НДС по ставке 0%;
		 * «vat10» – НДС чека по ставке 10%;
		 * «vat12» – НДС чека по ставке 20%;
		 * «vat110» – НДС чека по расчетной ставке 10/110;
		 * «vat120» – НДС чека по расчетной ставке 20/120.
		 */
		$order = wc_get_order( $order_id );

		$items = [];
		// The loop to get the order items which are WC_Order_Item_Product objects since WC 3+
		foreach ( $order->get_items() as $item_id => $item_product ) {
			//Get the product ID
			$product_id = $item_product->get_product_id();

			$description = $item_product->get_name();
			$category    = '';
			$categories  = get_the_terms( $product_id, 'product_cat' );
			if ( ! empty( $categories ) ) {
				$category = $categories[0]->name;
			}
			$by_categories[ $category ][] = $description;

			// ( ! empty( $category ) ? $category . ': ' : '' ) .
			$items[] = [
				'name'     => $this->cut_words(
					strtr( $item_product->get_name(), [ '.' => '', ',' => '' ] ),
					$max_length = 64 ),
				'quantity' => (int) $item_product->get_quantity(),
				'sum'      => (float) $item_product->get_total(),
				'tax'      => 'none',
				// 'payment_method' => 'full_prepayment',
				// 'payment_object' => 'service', // not necessary
			];

		}

		// FIXME: Always use USN Income
		$receipt = [
			'sno'   => 'usn_income',
			'items' => $items,
		];

		return json_encode( $receipt, JSON_UNESCAPED_UNICODE );
	}

	function cut_words( $words, $max_length = 100 ) {
		if ( strlen( $words ) > $max_length ) {
			$words = implode(
				' ',
				array_slice(
					explode( ' ', substr( $words, 0, $max_length - 1 ) ),
					0, - 1 ) );
		}

		return $words;
	}

	function log_data( $data ) {
		if ( $this->debug && ! empty( MIND_PATH_TMP ) ) {
			$data = "\n" . date( 'Y-m-d H:i:s' ) . "\t" . var_export( $data, true );
			file_put_contents( MIND_PATH_TMP . "woo-robokassa.log", $data, FILE_APPEND );
		}
	}

	function get_order_description( $order_id ) {
		/**
		 * Return string with order description generated from items
		 *
		 */
		$order = wc_get_order( $order_id );

		$by_categories = [];
		// The loop to get the order items which are WC_Order_Item_Product objects since WC 3+
		foreach ( $order->get_items() as $item_id => $item_product ) {
			//Get the product ID
			$product_id = $item_product->get_product_id();

			$description = $item_product->get_name();
			$category    = '';
			$categories  = get_the_terms( $product_id, 'product_cat' );
			if ( ! empty( $categories ) ) {
				$category = $categories[0]->name;
			}
			$by_categories[ $category ][] = $description;

		}

		$description = '';
		foreach ( $by_categories as $category => $products ) {
			//$description .= ( ! empty( $category ) ? $category . ': ' : '' ) . implode( ', ', $products );
			$description .= implode( ', ', $products );
		}

		return $this->cut_words( $description, $max_length = 100 );
	}

	/**
	 * Check Response
	 **/
	function check_ipn_response() {
		if ( isset( $_GET['robokassa'] ) ) {
			if ( $_GET['robokassa'] == 'result' ) {
				@ob_clean();

				$_POST = stripslashes_deep( $_POST );

				if ( $this->check_ipn_request_is_valid( $_POST ) ) {
					do_action( 'valid-robokassa-standard-ipn-request', $_POST );
				} else {
					wp_die( 'IPN Request Failure' );
				}
			} else if ( $_GET['robokassa'] == 'success' ) {
				$inv_id = $_POST['InvId'];
				$order  = new WC_Order( $inv_id );

				WC()->cart->empty_cart();

				wp_redirect( $this->get_return_url( $order ) );
				exit;
			} else if ( $_GET['robokassa'] == 'fail' ) {
				$inv_id = $_POST['InvId'];
				$order  = new WC_Order( $inv_id );
				$order->update_status( 'failed', __( 'Платеж не оплачен', 'robokassa-payment-gateway-saphali' ) );

				wp_redirect( str_replace( '&amp;', '&', $order->get_cancel_order_url() ) );
				exit;
			}
		}

	}

	/**
	 * Check RoboKassa IPN validity
	 *
	 * @param $posted
	 *
	 * @return bool
	 */
	function check_ipn_request_is_valid( $posted ) {
		$out_summ = $posted['OutSum'];
		$inv_id   = $posted['InvId'];
		if ( empty( $this->outsumcurrency ) ) {
			$sign = strtoupper( hash( 'sha256', $out_summ . ':' . $inv_id . ':' . $this->robokassa_key2 ) );
		} else {
			$sign = strtoupper( hash( 'sha256', $out_summ . ':' . $inv_id . ':' . $this->outsumcurrency . ':'
			                                    . $this->robokassa_key2 ) );
		}

		if ( $posted['SignatureValue'] == strtoupper(
				hash( 'sha256', $out_summ . ':' . $inv_id . ':' . $this->robokassa_key2 ) )
		     || $posted['SignatureValue'] == $sign ) {
			echo 'OK' . $inv_id;

			return true;
		}

		return false;
	}

	/**
	 * Successful Payment!
	 *
	 * @param $posted
	 */
	function successful_request( $posted ) {
		// logging
		$posted['log_method'] = 'successful_request()';
		$posted['log_ip']     = MTools::remoteAddr();
		$this->log_data( $posted );

		// $out_summ = $posted['OutSum'];
		$inv_id = $posted['InvId'];

		$order = new WC_Order( $inv_id );

		// Check order not already completed
		if ( $order->status == 'completed' ) {
			exit;
		}

		// Payment completed
		$order->add_order_note( __( 'Платеж успешно завершен.', 'robokassa-payment-gateway-saphali' ) );
		$order->payment_complete();
		exit;
	}
}

?>