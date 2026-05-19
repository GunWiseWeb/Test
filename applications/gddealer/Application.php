<?php
/**
 * @brief       GD Dealer Manager Application Class
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 */

namespace IPS\gddealer;

class _Application extends \IPS\Application
{
	/**
	 * ACP menu icon (Font Awesome name, no `fa-` prefix).
	 *
	 * Must be public to match the parent \IPS\Application::get__icon()
	 * signature (Rule #11). The ACP sidebar tab glyph is actually driven
	 * by the language key menutab__gddealer_icon in lang.xml (Rule #14);
	 * this method exists for other parts of IPS that query it.
	 */
	public function get__icon(): string
	{
		return 'store';
	}

	/**
	 * Install 'other' items — runs after schema.json tables are created.
	 * Seeds templates into core_theme_templates and creates default subscription
	 * tier records.
	 */
	public function installOther()
	{
		require_once \IPS\ROOT_PATH . '/applications/gddealer/setup/install.php';

		try {
			if ( class_exists( '\\IPS\\Theme\\Dev\\Theme' ) ) {
				\IPS\Theme\Dev\Theme::importDevCss( 'gddealer', 0 );
			}
		} catch ( \Throwable $e ) {
			try { \IPS\Log::log( 'installOther importDevCss failed: ' . $e->getMessage(), 'gddealer_install' ); }
			catch ( \Throwable ) {}
		}
	}

	/**
	 * acpMenuNumber — IPS 5 red-pill badge count for AdminCP menu items.
	 * IPS invokes this on every AdminCP menu item with the item's raw query
	 * string. Return an int count to show a badge; 0 hides it. Used by
	 * Nexus and other core apps; no extension class involved.
	 */
	public function acpMenuNumber( string $queryString ): int
	{
		parse_str( $queryString, $query );
		$controller = (string) ( $query['controller'] ?? '' );
		$do         = (string) ( $query['do'] ?? '' );

		if ( $controller === 'support' && $do === '' )
		{
			try
			{
				return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_support_tickets',
					[ "(status = ?) OR (status = ? AND last_reply_role = ?)",
					  'open', 'pending_staff', 'dealer' ]
				)->first();
			}
			catch ( \Exception ) { return 0; }
		}

		if ( $controller === 'dealers' && $do === 'disputes' )
		{
			try
			{
				return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_ratings',
					[ "dispute_status = ? OR (dispute_status = ? AND dispute_deadline IS NOT NULL AND dispute_deadline < ?)",
					  'pending_admin', 'pending_customer', date( 'Y-m-d H:i:s' ) ]
				)->first();
			}
			catch ( \Exception ) { return 0; }
		}

		if ( $controller === 'dealers' && $do === 'fflVerifications' )
		{
			try
			{
				/* Pending = submitted but not yet verified and not yet rejected. */
				return (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_dealer_feed_config',
					[ 'ffl_submitted_at IS NOT NULL AND ffl_verified_at IS NULL AND ffl_rejection_reason IS NULL AND ffl_rejection_count < ?', 3 ]
				)->first();
			}
			catch ( \Exception ) { return 0; }
		}

		return 0;
	}
}

class Application extends _Application {}
