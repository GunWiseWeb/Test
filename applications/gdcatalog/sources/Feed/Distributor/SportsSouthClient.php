<?php
/**
 * @brief       Sports South Web Services API Client
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       v1.0.8 (parser corrected in v1.0.10)
 *
 * HTTP POST client for Sports South's .asmx web services. Uses POST
 * form-encoded calls (not full SOAP envelopes) per their docs.
 *
 * v1.0.10 changes from v1.0.8 disk-edits baked in:
 *   - HTTP 200 check uses (int) cast for type safety (response code may be string)
 *   - parseTableRows extracts <string> wrapper before parsing inner XML
 *   - parseTableRows uses getElementsByTagNameNS('*', 'Table') for namespace handling
 *
 * Response shape (DailyItemUpdate):
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

	/** Image URL templates. Substitute {PICREF}. */
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

	/**
	 * Pull products via DailyItemUpdate.
	 *
	 * @param  string $lastUpdate  Date "M/d/yyyy" - use "1/1/1990" for full catalog
	 * @param  int    $lastItem    Last item number for paging (0 starts from beginning)
	 * @return array<int,array<string,string>>
	 */
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

	/**
	 * Lightweight count call.
	 *
	 * @param  string $lastUpdate  Date "M/d/yyyy"
	 * @return int
	 */
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
	 * Build the large-image URL from a PICREF value.
	 * If PICREF is empty, falls back to using ITEMNO.
	 */
	public static function imageUrlForPicref( string $picref, string $fallbackItemno = '' ): string
	{
		$ref = $picref !== '' ? $picref : $fallbackItemno;
		if ( $ref === '' )
		{
			return '';
		}
		return sprintf( self::IMAGE_URL_LARGE, urlencode( $ref ) );
	}

	/**
	 * Issue a POST to a Sports South method.
	 */
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

		/* HTTP code may be returned as string from some HTTP backends; cast for safe comparison. */
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

	/**
	 * Parse Sports South response XML into Table rows.
	 *
	 * Sports South wraps responses in <string>...</string> where the content
	 * is HTML-entity-encoded inner XML. The DOM parser auto-decodes the
	 * entities when we read the <string> element's nodeValue, giving us
	 * clean XML to re-parse.
	 */
	protected function parseTableRows( string $xml ): array
	{
		if ( $xml === '' )
		{
			return [];
		}

		$prevEntityState = null;
		if ( \function_exists( 'libxml_disable_entity_loader' ) )
		{
			$prevEntityState = libxml_disable_entity_loader( true );
		}
		$prevUseErrors = libxml_use_internal_errors( true );

		try
		{
			$outerDoc = new \DOMDocument();
			$outerDoc->loadXML( $xml );

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
			$dataDoc->loadXML( $innerXml );

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
			if ( $prevEntityState !== null && \function_exists( 'libxml_disable_entity_loader' ) )
			{
				libxml_disable_entity_loader( $prevEntityState );
			}
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
}
