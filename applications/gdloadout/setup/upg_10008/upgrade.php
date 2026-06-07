<?php

namespace IPS\gdloadout\setup\upg_10008;

if ( !\defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* 1. Re-import dev CSS and recompile to bust compiled CSS cache */
		try
		{
			if ( class_exists( '\\IPS\\Theme\\Dev\\Theme' ) )
			{
				\IPS\Theme\Dev\Theme::importDevCss( 'gdloadout', 0 );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			foreach ( \IPS\Theme::themes() as $theme )
			{
				try
				{
					if ( method_exists( $theme, 'compileCss' ) )
					{
						$theme->compileCss( 'gdloadout', 'front', '.', 'loadouts.css' );
					}
				}
				catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable ) {}

		/* 2. Clear all caches */
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }             catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }             catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
