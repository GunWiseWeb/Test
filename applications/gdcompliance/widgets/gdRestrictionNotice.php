<?php
/**
 * @brief  GD Compliance — Restriction Notice widget
 *
 * Placeable IPS widget that renders the "Sales Restrictions" panel for
 * the current product page. Reads UPC from the URL context (?upc= or
 * the segment captured by gdsearch's product FURL). Falls back to the
 * widget's configured UPC when none is in the URL — mostly useful for
 * previewing on a fixed-product page.
 *
 * Keeps the display loosely coupled: Derrick places this widget in the
 * product-page area via ACP → Customize; disabling gdcompliance removes
 * the widget class and the placement no-ops. NO gdcatalog/gdsearch
 * template edits required.
 */

namespace IPS\gdcompliance\widgets;

use IPS\Helpers\Form;
use IPS\Helpers\Form\Text;
use IPS\Widget\Customizable;
use IPS\Widget\PermissionCache;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class gdRestrictionNotice extends PermissionCache implements Customizable
{
	public string $key = 'gdRestrictionNotice';
	public string $app = 'gdcompliance';

	public function init(): void
	{
		/* CSS is inlined by Flag::renderNotice() once per request — no
		   separate cssFiles registration needed. */
		parent::init();
	}

	public function configuration( Form &$form=null ): Form
	{
		$form = parent::configuration( $form );
		$form->add( new Text( 'gdcompliance_widget_upc_fallback', $this->configuration['gdcompliance_widget_upc_fallback'] ?? '', FALSE ) );
		return $form;
	}

	public function render(): string
	{
		try
		{
			$enabled = (int) ( \IPS\Settings::i()->gdcompliance_front_enabled ?? 1 );
			if ( !$enabled ) { return ''; }
		}
		catch ( \Throwable ) {}

		$upc = $this->resolveUpc();
		if ( $upc === '' ) { return ''; }

		return \IPS\gdcompliance\Flag::renderNotice( $upc );
	}

	protected function resolveUpc(): string
	{
		$upc = '';
		try
		{
			$req = \IPS\Request::i();
			if ( isset( $req->upc ) )
			{
				$upc = trim( (string) $req->upc );
			}
		}
		catch ( \Throwable ) {}

		if ( $upc === '' )
		{
			try
			{
				$req = \IPS\Request::i();
				$path = (string) ( $req->url()?->data['path'] ?? '' );
				if ( $path !== '' && preg_match( '#/product/([^/\?\#]+)#', $path, $m ) )
				{
					$upc = trim( $m[1] );
				}
			}
			catch ( \Throwable ) {}
		}

		if ( $upc === '' )
		{
			$upc = trim( (string) ( $this->configuration['gdcompliance_widget_upc_fallback'] ?? '' ) );
		}

		return preg_replace( '/[^0-9A-Za-z\-]/', '', $upc ) ?? '';
	}
}
