<?php
/**
 * @brief       GD Dealer Manager — XML Feed Parser
 * @package     IPS Community Suite
 * @subpackage  GD Dealer Manager
 * @since       15 Apr 2026
 *
 * Safe XML feed parsing. LIBXML_NONET is passed to simplexml_load_string
 * to disable network entity loading and prevent XXE attacks. (The legacy
 * libxml_disable_entity_loader() call was removed in v208 as that function
 * is deprecated in PHP 8.0+ and triggers fatal errors in some IPS error
 * handler contexts.)
 */

namespace IPS\gddealer\Feed\Parser;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class XmlParser
{
	/**
	 * Parse a raw XML feed body into a flat list of record arrays.
	 *
	 * @return array<int, array<string, mixed>>
	 * @throws \RuntimeException on parse failure
	 */
	public static function parse( string $body ): array
	{
		/* XXE protection: LIBXML_NONET below disables network entity loading,
		 * which is the effective guard. libxml_disable_entity_loader() was
		 * removed/deprecated in PHP 8.0+ and triggers fatal errors when
		 * called, so it's intentionally absent here. */
		libxml_use_internal_errors( true );

		$sx = simplexml_load_string( $body, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA );
		if ( $sx === false )
		{
			$err = libxml_get_last_error();
			throw new \RuntimeException( 'XML parse error: ' . ( $err ? $err->message : 'unknown' ) );
		}

		/* Find the repeating child element. The expected layout is:
		 *   <root> <record/> <record/> </root>     (records direct children)
		 *
		 * But many feeds wrap records in a single container element:
		 *   <root> <listings> <listing/> <listing/> </listings> </root>
		 *   <root> <products> <product/> <product/> </products> </root>
		 *
		 * If the root has exactly one child AND that child has its own
		 * children, descend one level. This mirrors JsonParser's
		 * wrapper-object detection so {"listings": [...]} works as well as
		 * a top-level array. */
		$children = $sx->children();
		if ( !count( $children ) )
		{
			return [];
		}
		if ( count( $children ) === 1 )
		{
			$onlyChild = $children[0];
			/* Only descend if the wrapper's children look like multiple
			 * records (>= 2 children with the same tag). A single child of
			 * the root with scalar leaves is a record, not a wrapper. */
			$grandchildren = $onlyChild->children();
			if ( count( $grandchildren ) >= 2 )
			{
				$firstName = '';
				$allSame = true;
				foreach ( $grandchildren as $gc )
				{
					$name = $gc->getName();
					if ( $firstName === '' ) { $firstName = $name; }
					elseif ( $name !== $firstName ) { $allSame = false; break; }
				}
				if ( $allSame )
				{
					$children = $grandchildren;
				}
			}
		}

		$records = [];
		foreach ( $children as $node )
		{
			$records[] = self::flatten( $node );
		}
		return $records;
	}

	/**
	 * Flatten a SimpleXMLElement to a flat associative array of string values.
	 * Attributes are included with an `@attr` prefix so field mapping can
	 * target either element or attribute values.
	 */
	protected static function flatten( \SimpleXMLElement $node, string $prefix = '' ): array
	{
		$out = [];

		foreach ( $node->attributes() as $aName => $aVal )
		{
			$out[ ( $prefix === '' ? '' : $prefix . '.' ) . '@' . $aName ] = (string) $aVal;
		}

		foreach ( $node->children() as $k => $child )
		{
			$key = $prefix === '' ? (string) $k : $prefix . '.' . $k;
			if ( count( $child->children() ) || count( $child->attributes() ) )
			{
				foreach ( self::flatten( $child, $key ) as $kk => $vv )
				{
					$out[ $kk ] = $vv;
				}
				if ( trim( (string) $child ) !== '' )
				{
					$out[ $key ] = (string) $child;
				}
			}
			else
			{
				$out[ $key ] = (string) $child;
			}
		}

		return $out;
	}
}
