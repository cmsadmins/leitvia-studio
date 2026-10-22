<?php
/**
 * Signatures of the WooCommerce functions and classes Studio calls, for PHPStan only (scanFiles). Never loaded at
 * runtime; PHPUnit loads the real WooCommerce for the group woocommerce.
 *
 * @package LeitviaStudio
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals, Generic.Files.OneObjectStructurePerFile, Universal.Files.SeparateFunctionsFromOO, Generic.CodeAnalysis.UnusedFunctionParameter, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.ValidFunctionName

class WooCommerce {
	public WC_Countries $countries;
}

class WC_Countries {
	public function inc_tax_or_vat(): string {}
	public function ex_tax_or_vat(): string {}
}

class WC_Product {
	public function get_id(): int {}
	public function get_name(): string {}
	public function get_status(): string {}
	public function get_post_password(): string {}
	public function get_permalink(): string {}
	public function get_price_html(): string {}
	public function get_average_rating(): float|string {}
	public function get_review_count(): int {}
	public function get_stock_status( string $context = 'view' ): string {}
	public function get_parent_id( string $context = 'view' ): int {}
	public function get_slug( string $context = 'view' ): string {}
	public function get_description( string $context = 'view' ): string {}
	public function get_short_description( string $context = 'view' ): string {}
	public function get_image_id( string $context = 'view' ): int|string {}
	/** @return array<int|string> */
	public function get_gallery_image_ids( string $context = 'view' ): array {}
	public function get_price( string $context = 'view' ): string {}
	public function get_regular_price( string $context = 'view' ): string {}
	public function get_sale_price( string $context = 'view' ): string {}
	public function get_tax_status( string $context = 'view' ): string {}
	public function get_tax_class( string $context = 'view' ): string {}
	public function get_shipping_class_id( string $context = 'view' ): int {}
	public function get_featured( string $context = 'view' ): bool {}
	/** @return array<int|string> */
	public function get_category_ids( string $context = 'view' ): array {}
	/** @return array<int|string> */
	public function get_tag_ids( string $context = 'view' ): array {}
	/** @return array<int|string> */
	public function get_upsell_ids( string $context = 'view' ): array {}
	/** @return array<int|string> */
	public function get_cross_sell_ids( string $context = 'view' ): array {}
	/** @return array<int> */
	public function get_children(): array {}
	public function get_type(): string {}
	/** @return array<string, WC_Product_Attribute|string> */
	public function get_attributes(): array {}
	/**
	 * @param array<string, mixed> $props Props.
	 * @return bool|WP_Error
	 */
	public function set_props( array $props ) {}
	public function save(): int {}
	public function get_attribute( string $attribute ): string {}
	public function is_type( string|array $type ): bool {}
	public function is_purchasable(): bool {}
	public function is_in_stock(): bool {}
	public function add_to_cart_url(): string {}
}

class WC_Product_Attribute {
	public function get_name(): string {}
	/** @return array<int|string> */
	public function get_options(): array {}
	public function is_taxonomy(): bool {}
	public function get_visible(): bool {}
	public function get_variation(): bool {}
}

class WC_Tax {
	/** @return list<string> */
	public static function get_tax_class_slugs(): array {}
}

function WC(): WooCommerce {}

function get_woocommerce_currency(): string {}

/**
 * @param int                  $product_id Product ID.
 * @param string               $taxonomy   Taxonomy.
 * @param array<string, mixed> $args       Arguments.
 * @return array<int, mixed>
 */
function wc_get_product_terms( int $product_id, string $taxonomy, array $args = array() ): array {}

/**
 * @param mixed $the_product Product ID, post or object.
 * @return WC_Product|null|false
 */
function wc_get_product( $the_product = false ) {}

function wc_tax_enabled(): bool {}

function wc_review_ratings_enabled(): bool {}

/**
 * @return array<string, string>
 */
function wc_get_product_stock_status_options(): array {}

function wc_attribute_label( string $name, mixed $product = '' ): string {}
