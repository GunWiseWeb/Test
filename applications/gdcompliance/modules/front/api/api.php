<?php
/**
 * @brief  GD Compliance — Public Compliance API (Product A, Stage 1)
 *
 * Machine-to-machine JSON API for dealer integrations. Two actions
 * live under the /compliance-api/ FURL:
 *
 *   GET|POST /compliance-api/check   ?upc=&state=           → single verdict
 *   POST     /compliance-api/batch   body {state, upcs[]}   → array of verdicts
 *
 * Auth: `Authorization: Bearer {key}` header (preferred) or `api_key`
 * request param fallback. Keys live in gd_compliance_api_keys and are
 * created manually in the ACP (Stage 1). Nexus auto-generation is
 * Stage 2; rate limiting is Stage 3.
 *
 * Design decisions worth calling out:
 *
 *   - This controller is NEVER browser-facing. No CSRF, no session
 *     requirement, no theme wrapper. Every response terminates via
 *     $this->respond() → \IPS\Output::i()->sendOutput() with an
 *     application/json Content-Type and Cache-Control: no-store.
 *
 *   - FURL chosen as /compliance-api/ (NOT /api/compliance/) — the
 *     latter would collide with IPS 5's REST framework at /api/.
 *
 *   - Verdict logic is delegated to \IPS\gdcompliance\Verdict::for()
 *     so the API and the public /state-lookup/ page share ONE source
 *     of truth for classification. No forked logic.
 *
 *   - Response embeds the disclaimer + verification_status flag so
 *     the dealer's frontend inherits both. Verification stays
 *     "pending_legal_review" until Derrick sets
 *     gdcompliance_api_verified=1 after legal sign-off — protects
 *     early test integrations from treating unverified data as
 *     authoritative.
 *
 *   - Suspended key → HTTP 402 (sets up the Stage-2 auto-block on
 *     missed payment). Revoked → 401. Invalid/missing → 401. All
 *     failures use short machine-readable error slugs, never
 *     enumerable data.
 */

namespace IPS\gdcompliance\modules\front\api;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _api extends \IPS\Dispatcher\Controller
{
	/** No CSRF — this is machine-to-machine, keys are the credential. */
	public static bool $csrfProtected = FALSE;

	/** Batch cap. Enforced hard; over-cap POSTs are rejected. */
	const BATCH_MAX = 200;

	/** Valid state codes (mirrors lookup controller). */
	const STATE_CODES = [
		'AL','AK','AZ','AR','CA','CO','CT','DC','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];

	/**
	 * Publicly viewable — no login / permission check. Auth is the
	 * API key, enforced in each action.
	 */
	public function execute(): void
	{
		parent::execute();
	}

	/**
	 * Default action (no `do` param). Not a real endpoint — return
	 * a "usage" JSON so a curious visitor gets something structured.
	 */
	protected function manage(): void
	{
		$this->respond( [
			'name'      => 'gunrack-compliance-api',
			'version'   => 1,
			'endpoints' => [
				'check' => '/compliance-api/check?upc=UPC&state=XX',
				'batch' => 'POST /compliance-api/batch  body:{"state":"XX","upcs":[...]}',
			],
			'auth'      => 'Authorization: Bearer {api_key}',
			'docs'      => 'https://gunrack.deals/compliance-api',
		], 200 );
	}

	/**
	 * GET /compliance-api/check
	 * POST /compliance-api/check
	 *
	 * Params: upc (required), state (required 2-char).
	 * Returns: one verdict payload (Fix 5 shape).
	 */
	public function check(): void
	{
		$key = $this->authenticate();
		if ( $key === null ) { return; }   /* authenticate() already responded */

		$upcRaw = trim( (string) ( \IPS\Request::i()->upc ?? '' ) );
		$upc    = substr( $upcRaw, 0, 64 );
		if ( $upc === '' || !preg_match( '/^[A-Za-z0-9\-\._\/ ]+$/', $upc ) )
		{
			$this->respond( [
				'error'   => 'invalid_upc',
				'message' => 'The upc parameter is required and must be alphanumeric.',
			], 400 );
			return;
		}

		$stateRaw = strtoupper( trim( (string) ( \IPS\Request::i()->state ?? '' ) ) );
		if ( !in_array( $stateRaw, self::STATE_CODES, true ) )
		{
			$this->respond( [
				'error'   => 'invalid_state',
				'message' => 'The state parameter is required and must be a 2-char US state code.',
			], 400 );
			return;
		}

		$verdict = $this->buildVerdictPayload( $upc, $stateRaw );
		$this->respond( $verdict, 200 );
	}

	/**
	 * POST /compliance-api/batch
	 *
	 * Body (JSON preferred, form fallback):
	 *   { "state": "IL", "upcs": ["A","B",...] }   /   state=IL&upcs[]=A&upcs[]=B
	 *
	 * Response: { state, results:[verdict,...], disclaimer,
	 *             verification_status, generated_at }.
	 */
	public function batch(): void
	{
		$key = $this->authenticate();
		if ( $key === null ) { return; }

		/* Body parse — prefer application/json. */
		$body      = [];
		$rawBody   = (string) ( file_get_contents( 'php://input' ) ?: '' );
		$ctype     = (string) ( $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '' );
		if ( $rawBody !== '' && stripos( $ctype, 'application/json' ) !== false )
		{
			$decoded = json_decode( $rawBody, true );
			if ( is_array( $decoded ) ) { $body = $decoded; }
		}

		$stateRaw = strtoupper( trim( (string) ( $body['state'] ?? \IPS\Request::i()->state ?? '' ) ) );
		if ( !in_array( $stateRaw, self::STATE_CODES, true ) )
		{
			$this->respond( [
				'error'   => 'invalid_state',
				'message' => 'The state field is required and must be a 2-char US state code.',
			], 400 );
			return;
		}

		$upcs = [];
		if ( isset( $body['upcs'] ) && is_array( $body['upcs'] ) )
		{
			$upcs = $body['upcs'];
		}
		else
		{
			$fallback = \IPS\Request::i()->upcs;
			if ( is_array( $fallback ) ) { $upcs = $fallback; }
		}

		/* Normalize + validate the UPC list. */
		$clean = [];
		foreach ( $upcs as $u )
		{
			$s = substr( trim( (string) $u ), 0, 64 );
			if ( $s !== '' && preg_match( '/^[A-Za-z0-9\-\._\/ ]+$/', $s ) )
			{
				$clean[] = $s;
			}
		}
		$clean = array_values( array_unique( $clean ) );

		if ( empty( $clean ) )
		{
			$this->respond( [
				'error'   => 'invalid_upcs',
				'message' => 'Provide upcs as a non-empty array of UPC strings.',
			], 400 );
			return;
		}
		if ( count( $clean ) > self::BATCH_MAX )
		{
			$this->respond( [
				'error'   => 'batch_too_large',
				'message' => 'Batch size exceeds cap of ' . self::BATCH_MAX . ' UPCs.',
				'max'     => self::BATCH_MAX,
			], 413 );
			return;
		}

		$results = [];
		foreach ( $clean as $u )
		{
			$results[] = $this->buildVerdictPayload( $u, $stateRaw, false );
		}

		$this->respond( array_merge( [
			'state'   => $stateRaw,
			'results' => $results,
		], $this->envelopeMeta() ), 200 );
	}

	/* ==================================================================
	 * Auth
	 * ================================================================== */

	/**
	 * Extract + validate the API key. Terminates the response with the
	 * correct status code on failure and returns null so the caller
	 * bails out. On success returns the key row array.
	 *
	 * Status codes match Fix 3:
	 *   missing/invalid key       → 401 invalid_api_key
	 *   status=revoked            → 401 revoked
	 *   status=suspended          → 402 subscription_inactive
	 *   status=active             → proceed
	 *
	 * On success we best-effort bump last_used_at + request_count. A
	 * DB failure on that update does NOT fail the request (metering
	 * is a cross-cutting concern and Stage-3 will formalize it).
	 */
	protected function authenticate(): ?array
	{
		$token = $this->extractBearerToken();
		if ( $token === '' )
		{
			$this->respond( [
				'error'   => 'invalid_api_key',
				'message' => 'Missing API key. Send Authorization: Bearer <key>.',
			], 401 );
			return null;
		}

		$row = null;
		try
		{
			$row = \IPS\Db::i()->select( '*', 'gd_compliance_api_keys', [ 'api_key=?', $token ] )->first();
		}
		catch ( \Throwable ) { $row = null; }

		if ( !is_array( $row ) )
		{
			$this->respond( [
				'error'   => 'invalid_api_key',
				'message' => 'The provided API key is not recognized.',
			], 401 );
			return null;
		}

		$status = (string) ( $row['status'] ?? 'active' );
		if ( $status === 'revoked' )
		{
			$this->respond( [
				'error'   => 'revoked',
				'message' => 'This API key has been revoked.',
			], 401 );
			return null;
		}
		if ( $status === 'suspended' )
		{
			$this->respond( [
				'error'   => 'subscription_inactive',
				'message' => 'This API key is suspended. Check your subscription.',
			], 402 );
			return null;
		}
		if ( $status !== 'active' )
		{
			$this->respond( [
				'error'   => 'invalid_api_key',
				'message' => 'The provided API key is not active.',
			], 401 );
			return null;
		}

		/* Best-effort metering — never blocks the successful path. */
		try
		{
			\IPS\Db::i()->update(
				'gd_compliance_api_keys',
				[
					'last_used_at'  => time(),
					'request_count' => 'request_count + 1',
				],
				[ 'id=?', (int) $row['id'] ],
				null, null,
				[ 'request_count' => 'raw' ] // no-op flag for future-proofing
			);
		}
		catch ( \Throwable )
		{
			/* Fallback simple increment via read-modify-write. Rare
			   path — only hit if the raw-set signature above is
			   rejected by this IPS build. */
			try
			{
				\IPS\Db::i()->update(
					'gd_compliance_api_keys',
					[
						'last_used_at'  => time(),
						'request_count' => ( (int) ( $row['request_count'] ?? 0 ) ) + 1,
					],
					[ 'id=?', (int) $row['id'] ]
				);
			}
			catch ( \Throwable ) {}
		}

		return $row;
	}

	/**
	 * Extract "Bearer <token>" from the Authorization header, with a
	 * fallback to an `api_key` request param for clients that can't
	 * set headers (mostly for testing). Trimmed; empty string = none.
	 */
	protected function extractBearerToken(): string
	{
		$auth = '';
		foreach ( [ 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ] as $k )
		{
			if ( isset( $_SERVER[ $k ] ) && (string) $_SERVER[ $k ] !== '' )
			{
				$auth = (string) $_SERVER[ $k ];
				break;
			}
		}
		if ( $auth === '' && function_exists( 'getallheaders' ) )
		{
			foreach ( getallheaders() as $name => $val )
			{
				if ( strcasecmp( (string) $name, 'Authorization' ) === 0 )
				{
					$auth = (string) $val;
					break;
				}
			}
		}
		if ( $auth !== '' && preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) )
		{
			return trim( $m[1] );
		}

		return trim( (string) ( \IPS\Request::i()->api_key ?? '' ) );
	}

	/* ==================================================================
	 * Response building
	 * ================================================================== */

	/**
	 * Wrap the raw Verdict::for() result in the API payload shape with
	 * disclaimer + verification_status + generated_at metadata. Set
	 * $includeMeta=false when embedding in a batch (batch envelope
	 * carries the meta once at the top level).
	 */
	protected function buildVerdictPayload( string $upc, string $stateCode, bool $includeMeta = true ): array
	{
		require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Verdict.php';
		$v = \IPS\gdcompliance\Verdict::for( $upc, $stateCode );

		$payload = [
			'upc'          => $v['upc'],
			'state'        => $v['state'],
			'status'       => $v['status'],
			'product'      => $v['product'],
			'restrictions' => $v['restrictions'],
			'advisories'   => $v['advisories'],
		];
		if ( $v['status'] === 'unknown' )
		{
			$payload['message'] = 'UPC not found in compliance database';
		}
		if ( $includeMeta )
		{
			foreach ( $this->envelopeMeta() as $k => $val )
			{
				$payload[ $k ] = $val;
			}
		}
		return $payload;
	}

	/**
	 * The metadata suffix (disclaimer, verification_status,
	 * generated_at) that trails every response envelope.
	 */
	protected function envelopeMeta(): array
	{
		$disclaimer = trim( (string) ( \IPS\Settings::i()->gdcompliance_api_disclaimer ?? '' ) );
		if ( $disclaimer === '' )
		{
			$disclaimer = 'This information is provided for general reference and is not legal advice or a guarantee of legality. Our engine catches the vast majority of restrictions but is never 100% accurate. Always verify with a licensed FFL and current state/local law.';
		}
		$verified = (int) ( \IPS\Settings::i()->gdcompliance_api_verified ?? 0 ) === 1;

		return [
			'disclaimer'          => $disclaimer,
			'verification_status' => $verified ? 'verified' : 'pending_legal_review',
			'generated_at'        => time(),
		];
	}

	/**
	 * Terminate the request with a JSON body + status code. Uses IPS's
	 * sendOutput() so headers land cleanly and no theme wrapper is
	 * injected. no-store because verdicts should never be cached by
	 * proxies (state law changes daily; false-negative caching is a
	 * liability).
	 */
	protected function respond( array $payload, int $status = 200 ): void
	{
		\IPS\Output::i()->sendOutput(
			(string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			$status,
			'application/json',
			[
				'Cache-Control' => 'no-store, no-cache, must-revalidate',
				'Pragma'        => 'no-cache',
			],
			FALSE,
			FALSE,
			FALSE
		);
	}
}

class api extends _api {}
