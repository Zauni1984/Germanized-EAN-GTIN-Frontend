/**
 * WooCommerce + Germanized: EAN/GTIN (ts_gtin / _ts_gtin)
 * - Zeigt EAN im Tab "Zusätzliche Informationen" (Eigenschaften)
 * - Zeigt EAN im SKU-Block (unter Artikelnummer) + QR-Code (clientseitig)
 * - Aktualisiert sich dynamisch bei Variationswechsel
 * - Fügt EAN ins Schema.org JSON-LD ein (gtin8/12/13/14)
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

/* ----------------------- Helpers ----------------------- */

function hj_get_gtin_meta( $post_id ) {
	$gtin = get_post_meta( $post_id, 'ts_gtin', true );
	if ( '' === trim( (string) $gtin ) ) {
		$gtin = get_post_meta( $post_id, '_ts_gtin', true ); // Germanized-Fallback
	}
	return is_string( $gtin ) ? trim( $gtin ) : '';
}

function hj_get_initial_gtin_for_product( WC_Product $product ) {
	$gtin = hj_get_gtin_meta( $product->get_id() );
	if ( $gtin !== '' ) {
		return $gtin;
	}
	if ( $product->is_type( 'variable' ) ) {
		foreach ( $product->get_children() as $child_id ) {
			$vg = hj_get_gtin_meta( $child_id );
			if ( $vg !== '' ) {
				return $vg;
			}
		}
	}
	return '';
}

function hj_map_gtin_key( $gtin_raw ) {
	$digits = preg_replace( '/\D+/', '', (string) $gtin_raw );
	$len    = strlen( $digits );
	if ( 8  === $len ) return array( 'gtin8',  $digits );
	if ( 12 === $len ) return array( 'gtin12', $digits );
	if ( 13 === $len ) return array( 'gtin13', $digits );
	if ( 14 === $len ) return array( 'gtin14', $digits );
	return array( 'gtin', $digits ); // Fallback
}

/* ---- 1) EAN in Eigenschaften-Tab (Standardausgabe bleibt) ---- */

add_filter( 'woocommerce_display_product_attributes', function( $product_attributes, $product ) {

	$label = __( 'EAN/GTIN', 'woocommerce' );

	if ( $product instanceof WC_Product_Simple ) {
		$gtin = hj_get_gtin_meta( $product->get_id() );
		if ( $gtin !== '' ) {
			$product_attributes['ean_gtin'] = array(
				'label' => $label,
				'value' => esc_html( $gtin ),
			);
		}
		return $product_attributes;
	}

	if ( $product instanceof WC_Product_Variable ) {
		$initial = hj_get_initial_gtin_for_product( $product );
		$product_attributes['ean_gtin'] = array(
			'label' => $label,
			'value' => '<span id="hj-ean" data-empty="&mdash;">' . ( $initial !== '' ? esc_html( $initial ) : '&mdash;' ) . '</span>',
		);
	}

	return $product_attributes;
}, 10, 2 );

/* ---- 2) Tab erzwingen, falls Woo ihn nicht setzt (Template bleibt Standard) ---- */

add_filter( 'woocommerce_product_tabs', function( $tabs ) {
	if ( ! isset( $tabs['additional_information'] ) ) {
		$tabs['additional_information'] = array(
			'title'    => __( 'Additional information', 'woocommerce' ),
			'priority' => 25,
			'callback' => function() { wc_get_template( 'single-product/tabs/additional-information.php' ); },
		);
	}
	return $tabs;
}, 20 );

/* ---- 3) Variation-Daten anreichern (damit JS die EAN hat) ---- */

add_filter( 'woocommerce_available_variation', function( $variation_data, $product, $variation ) {
	$gtin = hj_get_gtin_meta( $variation->get_id() );
	$variation_data['ts_gtin'] = $gtin !== '' ? $gtin : '';
	return $variation_data;
}, 10, 3 );

/* ---- 4) SKU-Block: EAN + QR-Container ausgeben ---- */

add_action( 'woocommerce_product_meta_end', function() {
	global $product;
	if ( ! $product instanceof WC_Product ) return;

	$initial = hj_get_initial_gtin_for_product( $product );
	$val     = $initial !== '' ? esc_html( $initial ) : '&mdash;';

	echo '<div class="product-ean-meta" style="margin-top:0.35em;">';
	echo '  <span class="sku_wrapper">'. esc_html__( 'EAN/GTIN:', 'woocommerce' ) .' ';
	echo '    <span id="hj-ean-sku-text" class="sku">'. $val .'</span>';
	echo '  </span>';
	echo '</div>';

	// QR-Container; wird clientseitig befüllt
	echo '<div id="hj-ean-qr" data-ean="'. esc_attr( $initial ) .'" style="margin-top:6px;"></div>';
}, 20 );

/* ---- 5) Frontend-Skripte: QR-Code + dynamisches Umschalten ---- */

add_action( 'wp_enqueue_scripts', function() {
	if ( ! is_product() ) return;

	// QR-Code Library (qrcodejs) von CDNJS
	wp_enqueue_script(
		'hj-qrcodejs',
		'https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js',
		array(),
		'1.0.0',
		true
	);

	// Unser Logik-Skript
	wp_register_script( 'hj-gtin-frontend', '', array( 'jquery', 'hj-qrcodejs' ), '1.0.0', true );

	$js = <<<'JS'
	(function($){
		'use strict';

		function setEANInAttributes(val){
			var $t = $('#hj-ean');
			if (!$t.length) return;
			if (val && String(val).trim().length){
				$t.text(val);
			} else {
				$t.html($t.data('empty') || '&mdash;');
			}
		}

		function setEANInSku(val){
			var $t = $('#hj-ean-sku-text');
			if (!$t.length) return;
			if (val && String(val).trim().length){
				$t.text(val);
			} else {
				$t.html('&mdash;');
			}
		}

		function renderQR(val){
			var el = document.getElementById('hj-ean-qr');
			if (!el) return;

			// Container leeren
			while (el.firstChild) { el.removeChild(el.firstChild); }

			if (!val || !window.QRCode) { return; }

			// QR erzeugen (128x128)
			new QRCode(el, {
				text: String(val),
				width: 128,
				height: 128,
				correctLevel: QRCode.CorrectLevel.M
			});
		}

		// Initial rendern
		$(function(){
			var el = document.getElementById('hj-ean-qr');
			var initVal = el ? (el.dataset ? el.dataset.ean : '') : '';
			renderQR(initVal);
		});

		// Variationswechsel
		$(document.body).on('found_variation', 'form.variations_form', function(e, variation){
			var val = (variation && typeof variation.ts_gtin !== 'undefined') ? variation.ts_gtin : '';
			setEANInAttributes(val);
			setEANInSku(val);
			renderQR(val);
		});

		// Reset
		$(document.body).on('reset_data', 'form.variations_form', function(){
			setEANInAttributes('');
			setEANInSku('');
			renderQR('');
		});

	})(jQuery);
JS;

	wp_enqueue_script( 'hj-gtin-frontend' );
	wp_add_inline_script( 'hj-gtin-frontend', $js, 'after' );
}, 30 );

/* ---- 6) Schema.org JSON-LD: gtin* einfügen ---- */

add_filter( 'woocommerce_structured_data_product', function( $data, $product ) {
	if ( ! $product instanceof WC_Product ) return $data;
	$gtin = hj_get_initial_gtin_for_product( $product );
	if ( $gtin === '' ) return $data;
	list( $key, $digits ) = hj_map_gtin_key( $gtin );
	if ( ! empty( $digits ) ) {
		$data[ $key ] = $digits;
	}
	return $data;
}, 10, 2 );

add_filter( 'woocommerce_structured_data_variation', function( $data, $product, $variation ) {
	if ( ! $variation instanceof WC_Product_Variation ) return $data;
	$gtin = hj_get_gtin_meta( $variation->get_id() );
	if ( $gtin === '' ) return $data;
	list( $key, $digits ) = hj_map_gtin_key( $gtin );
	if ( ! empty( $digits ) ) {
		$data[ $key ] = $digits;
	}
	return $data;
}, 10, 3 );
