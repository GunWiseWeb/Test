<?php
/**
 * @brief       Sports South Web Services API Client
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       v1.0.8 (extended v1.0.10 with parser fixes, v1.0.11 with lookups)
 *
 * v1.0.11 additions:
 *   - brandUpdate() - pulls all brands from BrandUpdate API
 *   - categoryUpdate() - pulls all categories from CategoryUpdate API
 *   - imageUrlForPicref() (already existed in v1.0.10) - builds large-size URL
 *
 * Response shape (all methods):
 *   <string xmlns="...">&lt;NewDataSet&gt;&lt;Table&gt;...&lt;/Table&gt;...&lt;/NewDataSet&gt;</string>
 *
 * Field reference for products (DailyItemUpdate):
 *   ITEMNO          Sports South SKU
 *   ITUPC           UPC barcode (used as gd_catalog primary key)
 *   IDESC           Short description (title)
 *   SHDESC          Long description
 *   IMFGNO          Manufacturer ID (joins to BrandUpdate.BRDNO)
 *   ITBRDNO         Brand ID (also joins to BrandUpdate.BRDNO)
 *   CATID           Category ID (joins to CategoryUpdate.CATID)
 *   IMODEL          Model name
 *   IPURPOSE        Use/purpose (hunting/target/etc)
 *   MFGINO          Manufacturer SKU
 *   PRC1            MSRP
 *   CPRC            Customer cost (the "C" price)
 *   QTYOH           Quantity on hand
 *   WTPBX           Weight per box (lbs)
 *   PICREF          Image reference (build URL: /large/{PICREF}.jpg)
 *   TXTREF          Reference for GetText API (full description)
 *   ITATR1-N        Per-category attribute values (labels in CategoryUpdate)
 */

namespace IPS\gdcatalog\Feed\Distributor;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class SportsSouthClient
{
	public const ENDPOINT_INVENTORY = 'http://webservices.theshootingwarehouse.com/smart/inventory.asmx';
	public const DEFAULT_TIMEOUT    = 120;
	public const SOURCE_CODE        = 'GUNRCK';

	public const IMAGE_URL_HIRES     = 'https://media.server.theshootingwarehouse.com/hires/%s.png';
	public const IMAGE_URL_LARGE     = 'https://media.server.theshootingwarehouse.com/large/%s.jpg';
	public const IMAGE_URL_SMALL     = 'https://media.server.theshootingwarehouse.com/small/%s.jpg';
	public const IMAGE_URL_THUMBNAIL = 'https://media.server.theshootingwarehouse.com/thumbnail/%s.jpg';

	protected string $userName;
	protected string $customerNumber;
	protected string $password;
	protected string $source;

	public function __construct( string $userName, string $customerNumber, string $password, string $source = self::SOURCE_CODE )
	{
		$this->userName       = $userName;
		$this->customerNumber = $customerNumber;
		$this->password       = $password;
		$this->source         = $source !== '' ? $source : self::SOURCE_CODE;
	}

	public static function fromDistributor( \IPS\gdcatalog\Feed\Distributor $feed ): self
	{
		$credsRaw = $feed->getCredentials();
		$creds    = $credsRaw ? json_decode( $credsRaw, true ) : [];

		if ( !is_array( $creds ) )
		{
			$creds = [];
		}

		return new self(
			(string) ( $creds['user_name']       ?? '' ),
			(string) ( $creds['customer_number'] ?? '' ),
			(string) ( $creds['password']        ?? '' ),
			(string) ( $creds['source']          ?? self::SOURCE_CODE )
		);
	}

	public function dailyItemUpdate( string $lastUpdate = '1/1/1990', int $lastItem = 0 ): array
	{
		$xml = $this->post( 'DailyItemUpdate', [
			'UserName'       => $this->userName,
			'CustomerNumber' => $this->customerNumber,
			'Password'       => $this->password,
			'Source'         => $this->source,
			'LastUpdate'     => $lastUpdate,
			'LastItem'       => (string) $lastItem,
		] );

		return $this->parseTableRows( $xml );
	}

	public function dailyItemCount( string $lastUpdate = '1/1/1990' ): int
	{
		$xml = $this->post( 'DailyItemCount', [
			'UserName'       => $this->userName,
			'CustomerNumber' => $this->customerNumber,
			'Password'       => $this->password,
			'Source'         => $this->source,
			'LastUpdate'     => $lastUpdate,
		] );

		if ( preg_match( '/<int[^>]*>(\d+)<\/int>/i', $xml, $m ) )
		{
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * v1.0.11: Pull all brands. Returns rows with BRDNO + BRDNAM (per Sports South docs).
	 *
	 * @return array<int,array<string,string>>
	 */
	public function brandUpdate(): array
	{
		$xml = $this->post( 'BrandUpdate', [
			'UserName'       => $this->userName,
			'CustomerNumber' => $this->customerNumber,
			'Password'       => $this->password,
			'Source'         => $this->source,
		] );

		return $this->parseTableRows( $xml );
	}

	/**
	 * v1.0.11: Pull all categories. Returns rows with CATID + CATDES + ATTR1..N (per Sports South docs).
	 *
	 * @return array<int,array<string,string>>
	 */
	public function categoryUpdate(): array
	{
		$xml = $this->post( 'CategoryUpdate', [
			'UserName'       => $this->userName,
			'CustomerNumber' => $this->customerNumber,
			'Password'       => $this->password,
			'Source'         => $this->source,
		] );

		return $this->parseTableRows( $xml );
	}

	public static function imageUrlForPicref( string $picref, string $fallbackItemno = '' ): string
	{
		$ref = $picref !== '' ? $picref : $fallbackItemno;
		if ( $ref === '' )
		{
			return '';
		}
		return sprintf( self::IMAGE_URL_LARGE, urlencode( $ref ) );
	}

	protected function post( string $method, array $params ): string
	{
		$url = self::ENDPOINT_INVENTORY . '/' . $method;

		try
		{
			$request  = \IPS\Http\Url::external( $url )->request( self::DEFAULT_TIMEOUT );
			$response = $request->post( $params );
		}
		catch ( \Throwable $e )
		{
			throw new \RuntimeException( 'Sports South request failed: ' . $e->getMessage() );
		}

		if ( (int) $response->httpResponseCode !== 200 )
		{
			throw new \RuntimeException( sprintf(
				'Sports South returned HTTP %d for method %s',
				(int) $response->httpResponseCode,
				$method
			) );
		}

		return (string) $response;
	}

	protected function parseTableRows( string $xml ): array
	{
		if ( $xml === '' )
		{
			return [];
		}

		$prevUseErrors = libxml_use_internal_errors( true );

		try
		{
			$outerDoc = new \DOMDocument();
			$outerDoc->loadXML( $xml, LIBXML_NONET );

			$innerXml = $xml;

			$stringEls = $outerDoc->getElementsByTagNameNS( '*', 'string' );
			if ( $stringEls->length > 0 )
			{
				$innerXml = (string) $stringEls->item( 0 )->nodeValue;
			}

			if ( $innerXml === '' )
			{
				return [];
			}

			$dataDoc = new \DOMDocument();
			$dataDoc->loadXML( $innerXml, LIBXML_NONET );

			$rows = [];

			$tableNodes = $dataDoc->getElementsByTagNameNS( '*', 'Table' );
			if ( $tableNodes->length === 0 )
			{
				$tableNodes = $dataDoc->getElementsByTagName( 'Table' );
			}

			foreach ( $tableNodes as $tableNode )
			{
				$row = [];
				foreach ( $tableNode->childNodes as $child )
				{
					if ( $child->nodeType === XML_ELEMENT_NODE )
					{
						$row[ $child->nodeName ] = trim( (string) $child->nodeValue );
					}
				}
				if ( !empty( $row ) )
				{
					$rows[] = $row;
				}
			}

			return $rows;
		}
		catch ( \Throwable $e )
		{
			throw new \RuntimeException( 'Sports South XML parse failed: ' . $e->getMessage() );
		}
		finally
		{
			libxml_use_internal_errors( $prevUseErrors );
		}
	}

	public function validate(): array
	{
		$errors = [];

		if ( $this->userName === '' )
		{
			$errors[] = 'UserName is required';
		}
		if ( $this->customerNumber === '' )
		{
			$errors[] = 'CustomerNumber is required';
		}
		if ( $this->password === '' )
		{
			$errors[] = 'Password is required';
		}
		if ( $this->source === '' || strlen( $this->source ) > 6 )
		{
			$errors[] = 'Source must be 1-6 characters';
		}

		return $errors;
	}

	public function getText( string $itemNumber ): string
	{
		$itemNumber = trim( $itemNumber );
		if ( $itemNumber === '' ) { return ''; }

		$xml = $this->post( 'GetText', [
			'UserName'       => $this->userName,
			'CustomerNumber' => $this->customerNumber,
			'Password'       => $this->password,
			'Source'         => $this->source,
			'ItemNumber'     => $itemNumber,
		] );
		if ( trim( $xml ) === '' ) { return ''; }

		$prev = libxml_use_internal_errors( true );
		try
		{
			$doc = new \DOMDocument();
			if ( !$doc->loadXML( $xml, LIBXML_NONET ) ) { return ''; }

			$strEls = $doc->getElementsByTagNameNS( '*', 'string' );
			if ( $strEls->length > 0 )
			{
				$stringEl = $strEls->item( 0 );
				$payload  = trim( (string) $stringEl->nodeValue );

				/* Case A: <string> holds entity-encoded XML */
				if ( $payload !== '' && $payload[0] === '<' )
				{
					$inner = new \DOMDocument();
					if ( @$inner->loadXML( $payload, LIBXML_NONET ) )
					{
						$t = $this->pickDescription( $inner, $itemNumber );
						if ( $t !== '' ) { return $t; }
					}
				}

				/* Case B: <string> has real child elements (ITEMNO + a text field, …) */
				if ( $stringEl->getElementsByTagName( '*' )->length > 0 )
				{
					$t = $this->pickDescription( $stringEl, $itemNumber );
					if ( $t !== '' ) { return $t; }
				}

				/* Case C: <string> is a leaf "ITEMNO + text" */
				if ( $payload !== '' )
				{
					return $this->cleanDescription( $this->stripLeadingItemNo( $payload, $itemNumber ) );
				}
			}

			return $this->pickDescription( $doc, $itemNumber );
		}
		catch ( \Throwable )
		{
			return '';
		}
		finally
		{
			libxml_use_internal_errors( $prev );
		}
	}

	protected function pickDescription( \DOMNode $scope, string $itemNumber ): string
	{
		foreach ( [ 'TEXT', 'ITEXT', 'LONGDESC', 'WEBDESC', 'DESCRIPTION', 'DESC' ] as $tag )
		{
			$n = $scope->getElementsByTagName( $tag );
			if ( $n->length > 0 )
			{
				$v = trim( (string) $n->item( 0 )->nodeValue );
				if ( $v !== '' && $v !== $itemNumber ) { return $this->cleanDescription( $this->stripLeadingItemNo( $v, $itemNumber ) ); }
			}
		}

		$best = '';
		foreach ( $scope->getElementsByTagName( '*' ) as $el )
		{
			$hasChildEl = false;
			foreach ( $el->childNodes as $c )
			{
				if ( $c->nodeType === XML_ELEMENT_NODE ) { $hasChildEl = true; break; }
			}
			if ( $hasChildEl ) { continue; }

			$v = trim( (string) $el->nodeValue );
			if ( $v === '' || $v === $itemNumber ) { continue; }
			if ( mb_strlen( $v ) > mb_strlen( $best ) ) { $best = $v; }
		}

		return $best !== '' ? $this->cleanDescription( $this->stripLeadingItemNo( $best, $itemNumber ) ) : '';
	}

	protected function stripLeadingItemNo( string $t, string $itemNumber ): string
	{
		$t = trim( $t );
		if ( $itemNumber !== '' && str_starts_with( $t, $itemNumber ) )
		{
			$rest = ltrim( substr( $t, strlen( $itemNumber ) ) );
			if ( $rest !== '' && !ctype_digit( $rest[0] ) ) { $t = $rest; }
		}
		return $t;
	}

	protected function cleanDescription( string $t ): string
	{
		$t = html_entity_decode( $t, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$t = strip_tags( $t );
		$t = preg_replace( '/[ \t]+/', ' ', $t );
		$t = preg_replace( '/\s*\n\s*\n\s*/', "\n\n", (string) $t );
		return trim( (string) $t );
	}
}
