<?php
/**
 * @brief       Sports South Web Services API Client
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       v1.0.8
 *
 * HTTP POST client for Sports South's .asmx web services. Sports South
 * documents that their endpoints accept GET/POST/SOAP; we use POST form-
 * encoded which is the simplest path from PHP (no SOAP envelope handling).
 *
 * The four required credentials per call:
 *   UserName        — Sports South account number (test: 99994)
 *   CustomerNumber  — same account number (test: 99994)
 *   Password        — web services password (test: 12345)
 *   Source          — six-char identifier (we use "GUNRCK")
 *
 * Response format: XML inside an ASP.NET DocumentElement wrapper. The
 * actual data rows are <Table>...</Table> children of <NewDataSet>.
 *
 * v1.0.8 implements only enough for connection testing:
 *   - dailyItemUpdate( $lastUpdate, $lastItem ) - returns array of parsed product rows
 *
 * Future versions will add:
 *   - brandUpdate(), categoryUpdate() for lookup tables
 *   - incrementalOnhandUpdate() for stock/price deltas
 *   - getText() for long descriptions
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
	/** Base URL for Sports South inventory web service. */
	public const ENDPOINT_INVENTORY = 'http://webservices.theshootingwarehouse.com/smart/inventory.asmx';

	/** Default request timeout in seconds. Sports South full pulls can be slow. */
	public const DEFAULT_TIMEOUT = 120;

	/** Six-char source identifier per Sports South docs. */
	public const SOURCE_CODE = 'GUNRCK';

	protected string $userName;
	protected string $customerNumber;
	protected string $password;
	protected string $source;

	/**
	 * Construct a client with explicit credentials.
	 *
	 * @param string $userName       Sports South account number
	 * @param string $customerNumber Sports South account number (same as userName for non-providers)
	 * @param string $password       Web services password
	 * @param string $source         Six-char source identifier (defaults to GUNRCK)
	 */
	public function __construct( string $userName, string $customerNumber, string $password, string $source = self::SOURCE_CODE )
	{
		$this->userName       = $userName;
		$this->customerNumber = $customerNumber;
		$this->password       = $password;
		$this->source         = $source !== '' ? $source : self::SOURCE_CODE;
	}

	/**
	 * Construct a client from a Distributor row's stored credentials.
	 *
	 * The auth_credentials column stores a JSON blob:
	 *   { "user_name": "...", "customer_number": "...", "password": "...", "source": "..." }
	 */
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
	 * Call DailyItemUpdate. Returns an array of associative product records.
	 *
	 * @param string $lastUpdate Date "1/1/1990" for full catalog, or "M/d/yyyy" for incremental
	 * @param int    $lastItem   Last item number from prior call (-1 returns all in one shot; 0 for first page)
	 * @return array<int,array<string,string>>
	 * @throws \RuntimeException on network/parse failure
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
	 * Lightweight count call to check connectivity + creds without pulling data.
	 *
	 * @param string $lastUpdate Date in M/d/yyyy format
	 * @return int Count of items changed since lastUpdate
	 * @throws \RuntimeException on network/parse failure
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

		/* Response is <int>NNNN</int> */
		if ( preg_match( '/<int[^>]*>(\d+)<\/int>/i', $xml, $m ) )
		{
			return (int) $m[1];
		}

		return 0;
	}

	/**
	 * Issue a POST to a Sports South method endpoint.
	 *
	 * @param  string $method        Method name (e.g. "DailyItemUpdate")
	 * @param  array  $params        POST params
	 * @return string Raw XML response
	 * @throws \RuntimeException
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

		if ( $response->httpResponseCode !== 200 )
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
	 * Parse the XML response into an array of <Table> rows.
	 *
	 * Sports South responses are wrapped in an ASP.NET DataSet envelope:
	 *   <DocumentElement>
	 *     <Table><FIELD1>val</FIELD1>...</Table>
	 *     <Table>...</Table>
	 *   </DocumentElement>
	 *
	 * Or sometimes wrapped further in a SOAP-style outer element.
	 * We extract all <Table> children regardless of root.
	 *
	 * @param  string $xml
	 * @return array<int,array<string,string>>
	 */
	protected function parseTableRows( string $xml ): array
	{
		if ( $xml === '' )
		{
			return [];
		}

		/* Security: disable external entity loading before parse */
		$prevEntityState = null;
		if ( \function_exists( 'libxml_disable_entity_loader' ) )
		{
			$prevEntityState = libxml_disable_entity_loader( true );
		}

		$prevUseErrors = libxml_use_internal_errors( true );

		try
		{
			$doc = new \DOMDocument();
			$doc->loadXML( $xml );

			$rows = [];

			$tableNodes = $doc->getElementsByTagName( 'Table' );
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

	/**
	 * Validate the credentials format without making a network call.
	 *
	 * @return array<int,string> Array of validation error messages; empty if valid
	 */
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
