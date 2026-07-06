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

	/**
	 * 2-letter state code → full state name. Used by the /product
	 * endpoint (v1.6.34) to attach human-readable names to each
	 * restricted/advisory state row.
	 */
	const STATE_NAMES = [
		'AL' => 'Alabama',       'AK' => 'Alaska',       'AZ' => 'Arizona',       'AR' => 'Arkansas',
		'CA' => 'California',    'CO' => 'Colorado',     'CT' => 'Connecticut',   'DE' => 'Delaware',
		'DC' => 'District of Columbia',
		'FL' => 'Florida',       'GA' => 'Georgia',      'HI' => 'Hawaii',        'ID' => 'Idaho',
		'IL' => 'Illinois',      'IN' => 'Indiana',      'IA' => 'Iowa',          'KS' => 'Kansas',
		'KY' => 'Kentucky',      'LA' => 'Louisiana',    'ME' => 'Maine',         'MD' => 'Maryland',
		'MA' => 'Massachusetts', 'MI' => 'Michigan',     'MN' => 'Minnesota',     'MS' => 'Mississippi',
		'MO' => 'Missouri',      'MT' => 'Montana',      'NE' => 'Nebraska',      'NV' => 'Nevada',
		'NH' => 'New Hampshire', 'NJ' => 'New Jersey',   'NM' => 'New Mexico',    'NY' => 'New York',
		'NC' => 'North Carolina','ND' => 'North Dakota', 'OH' => 'Ohio',          'OK' => 'Oklahoma',
		'OR' => 'Oregon',        'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',  'SC' => 'South Carolina',
		'SD' => 'South Dakota',  'TN' => 'Tennessee',    'TX' => 'Texas',         'UT' => 'Utah',
		'VT' => 'Vermont',       'VA' => 'Virginia',     'WA' => 'Washington',    'WV' => 'West Virginia',
		'WI' => 'Wisconsin',     'WY' => 'Wyoming',
	];

	/**
	 * v1.6.31 quota state — populated by authenticate() on success so
	 * respond() can attach standard X-RateLimit-* headers to every
	 * post-auth response. Shape:
	 *   [ 'is_unlimited' => bool, 'limit' => int|null, 'used' => int,
	 *     'remaining' => int|null, 'reset' => int ]
	 * Null when the request never reached authenticate() successfully
	 * (401/402 pre-auth) — respond() then emits no rate-limit headers.
	 */
	protected ?array $quotaState = null;

	/** Authenticated key row (post-auth). Used for the metering write. */
	protected ?array $authedKey = null;

	/**
	 * v1.6.34 — populated by authenticate() when a publishable key
	 * matches the request origin. respond() echoes it back as
	 * Access-Control-Allow-Origin so browsers accept the response
	 * cross-origin. Empty for secret keys / server-to-server calls.
	 */
	protected string $allowedOrigin = '';

	/** Valid state codes (mirrors lookup controller). */
	const STATE_CODES = [
		'AL','AK','AZ','AR','CA','CO','CT','DC','DE','FL','GA','HI','ID','IL','IN','IA','KS','KY','LA',
		'ME','MD','MA','MI','MN','MS','MO','MT','NE','NV','NH','NJ','NM','NY','NC','ND','OH','OK','OR',
		'PA','RI','SC','SD','TN','TX','UT','VT','VA','WA','WV','WI','WY',
	];

	/**
	 * Publicly viewable — no login / permission check. Auth is the
	 * API key, enforced in each action.
	 *
	 * v1.6.34: intercept CORS preflight OPTIONS requests BEFORE the
	 * parent dispatcher runs. Browsers send OPTIONS without auth as
	 * the first hop of a cross-origin call; we echo the Origin as
	 * Access-Control-Allow-Origin so the browser proceeds with the
	 * real request. The real request is still auth-gated normally.
	 */
	public function execute(): void
	{
		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'OPTIONS' )
		{
			$this->sendCorsPreflight();
			return;
		}
		parent::execute();
	}

	/**
	 * Send a bare 204 response with CORS headers echoing the request's
	 * Origin. Called on any OPTIONS request. No auth, no body — the
	 * browser only cares about the response headers.
	 */
	protected function sendCorsPreflight(): void
	{
		$origin  = trim( (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' ) );
		$headers = [
			'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
			'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
			'Access-Control-Max-Age'       => '600',
			'Cache-Control'                => 'no-store, no-cache, must-revalidate',
		];
		if ( $origin !== '' )
		{
			$headers['Access-Control-Allow-Origin'] = $origin;
			$headers['Vary']                        = 'Origin';
		}
		\IPS\Output::i()->sendOutput(
			'', 204, 'text/plain', $headers, FALSE, FALSE, FALSE
		);
	}

	/**
	 * Default action (no `do` param, or path form).
	 *
	 * DEFENSIVE PATH DISPATCH — v1.6.28 shipped with the FURL route
	 * `compliance-api/{@do}` and the `{@do}` dynamic segment did NOT
	 * populate the `do` request param on this IPS build. Path-style
	 * requests (…/check, …/batch) fell through here to the manifest
	 * instead of the intended action. v1.6.29 fixes this two ways:
	 *
	 *   1) `data/furl.json` now defines EXPLICIT check + batch routes
	 *      (both new /api/compliance/* and legacy /compliance-api/*),
	 *      so the FURL layer maps do=check / do=batch by hand — no
	 *      {@do} indirection.
	 *   2) This method ALSO inspects the trailing URI segment as a
	 *      backstop. If a request lands here with the URL ending in
	 *      /check or /batch, we route explicitly so a stale FURL
	 *      datastore cache from v1.6.28 can't leak into 1.6.29's
	 *      startup window.
	 *
	 * Otherwise: return the manifest/info blob (the correct fallback
	 * for /api/compliance and /compliance-api root URLs).
	 */
	protected function manage(): void
	{
		$uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
		$path = (string) ( parse_url( $uri, PHP_URL_PATH ) ?: '' );
		if ( preg_match( '~/check/?$~', $path ) )
		{
			$this->check();
			return;
		}
		if ( preg_match( '~/batch/?$~', $path ) )
		{
			$this->batch();
			return;
		}
		if ( preg_match( '~/mykey/?$~', $path ) )
		{
			$this->mykey();
			return;
		}
		if ( preg_match( '~/product/?$~', $path ) )
		{
			$this->product();
			return;
		}
		if ( preg_match( '~/docs/?$~', $path ) )
		{
			$this->docs();
			return;
		}

		$this->respond( [
			'name'      => 'gunrack-compliance-api',
			'version'   => 1,
			'endpoints' => [
				'check'   => '/api/compliance/check?upc=UPC&state=XX',
				'batch'   => 'POST /api/compliance/batch  body:{"state":"XX","upcs":[...]}',
				'product' => '/api/compliance/product?upc=UPC — all-states verdict (widget use)',
			],
			'auth'      => 'Authorization: Bearer {api_key}',
			'key_types' => [
				'secret'      => 'Server-to-server. Full access. Do NOT embed in browser JS.',
				'publishable' => 'Browser-safe. Domain-locked (Origin header must match registered domains). Read endpoints only.',
			],
			'docs'      => 'https://gunrack.deals/api/compliance/docs',
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

		/* v1.6.31 — count this served request against the monthly
		   quota + burst bucket. Do it BEFORE respond() so the
		   X-RateLimit-Remaining header reflects the post-increment
		   value (matches standard API behavior — the header shows the
		   remaining budget AFTER this call). */
		$this->accrue( 1 );

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

		/* v1.6.31 — batch counts each UPC as 1 quota unit (that's the
		   work). Document this on the mykey page and in the manifest. */
		$this->accrue( count( $clean ) );

		$this->respond( array_merge( [
			'state'   => $stateRaw,
			'results' => $results,
		], $this->envelopeMeta() ), 200 );
	}

	/**
	 * v1.6.34 — GET|POST /api/compliance/product?upc=UPC
	 *
	 * All-states verdict for a UPC. Returns arrays of restricted
	 * states + advisory states with reasons/citations, plus a
	 * compact restricted_state_codes list for at-a-glance display.
	 * This is what the dealer browser widget renders on a product
	 * page.
	 *
	 * Counts as 1 quota unit (same as single check()).
	 */
	public function product(): void
	{
		$key = $this->authenticate();
		if ( $key === null ) { return; }

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

		$payload = $this->buildProductPayload( $upc );

		$this->accrue( 1 );
		$this->respond( $payload, 200 );
	}

	/**
	 * Build the /product payload from gd_catalog + gd_compliance_flags.
	 * UPC-only match against gd_catalog (Stage-1 pattern). All flag
	 * rows returned; caller splits by advisory vs restrict.
	 */
	protected function buildProductPayload( string $upc ): array
	{
		$product = null;
		try
		{
			$product = \IPS\Db::i()->select(
				'upc, title, brand, manufacturer',
				'gd_catalog',
				[ 'upc=?', $upc ]
			)->first();
		}
		catch ( \Throwable ) { $product = null; }

		if ( !is_array( $product ) )
		{
			return array_merge( [
				'upc'                    => $upc,
				'status'                 => 'unknown',
				'product'                => null,
				'message'                => 'UPC not found in compliance database',
				'restricted_states'      => [],
				'advisory_states'        => [],
				'restricted_state_codes' => [],
			], $this->envelopeMeta() );
		}

		$brand = trim( (string) ( $product['brand'] ?? '' ) );
		if ( $brand === '' ) { $brand = trim( (string) ( $product['manufacturer'] ?? '' ) ); }
		$title = trim( (string) ( $product['title'] ?? '' ) );
		$name  = trim( ( $brand !== '' ? $brand . ' — ' : '' ) . $title );
		if ( $name === '' ) { $name = $title !== '' ? $title : null; }

		$restricted = [];
		$advisory   = [];
		$codeSet    = [];

		try
		{
			require_once \IPS\ROOT_PATH . '/applications/gdcompliance/sources/Verdict.php';
			foreach ( \IPS\Db::i()->select(
				'state_code, firearm_type, reason, citation',
				'gd_compliance_flags',
				[ 'upc=?', $upc ],
				'state_code ASC, firearm_type ASC'
			) as $r )
			{
				$state = strtoupper( (string) ( $r['state_code'] ?? '' ) );
				if ( $state === '' || !isset( self::STATE_NAMES[ $state ] ) ) { continue; }
				$ftype  = (string) ( $r['firearm_type'] ?? '' );
				$reason = (string) ( $r['reason']       ?? '' );
				$cite   = (string) ( $r['citation']     ?? '' );

				if ( $ftype === 'advisory' )
				{
					$advisory[] = [
						'state'      => $state,
						'state_name' => self::STATE_NAMES[ $state ],
						'reason'     => $reason,
						'citation'   => $cite,
					];
				}
				else
				{
					$restricted[] = [
						'state'      => $state,
						'state_name' => self::STATE_NAMES[ $state ],
						'type'       => \IPS\gdcompliance\Verdict::typeSlug( $ftype ),
						'reason'     => $reason,
						'citation'   => $cite,
					];
					$codeSet[ $state ] = true;
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'api product: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
		}

		return array_merge( [
			'upc'                    => $upc,
			'product'                => $name,
			'restricted_states'      => $restricted,
			'advisory_states'        => $advisory,
			'restricted_state_codes' => array_values( array_keys( $codeSet ) ),
		], $this->envelopeMeta() );
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

		/* v1.6.34 DOMAIN GATE (publishable keys only). Secret keys are
		   server-to-server and skip this — they don't carry an Origin.
		   Publishable keys are safe to embed in browser JS because a
		   leaked key only works from an origin the dealer registered. */
		$keyType = (string) ( $row['key_type'] ?? 'secret' );
		if ( $keyType === 'publishable' )
		{
			$origin = $this->requestOrigin();
			if ( $origin === '' )
			{
				$this->respond( [
					'error'   => 'domain_not_allowed',
					'message' => 'Publishable keys require an Origin or Referer header. Use a secret key for server-to-server calls.',
				], 403 );
				return null;
			}
			$allowedRaw = (string) ( $row['allowed_domains'] ?? '' );
			if ( !$this->originMatchesKey( $origin, $allowedRaw ) )
			{
				$this->respond( [
					'error'   => 'domain_not_allowed',
					'message' => 'This publishable key is not authorized for this origin.',
					'origin'  => $origin,
				], 403 );
				return null;
			}
			$this->allowedOrigin = $origin;
		}

		/* v1.6.30 SUBSCRIPTION GATE — live check that the key's owning
		   member is currently in an API-access group. IPS Commerce
		   already manages the group membership by subscription state
		   (add on purchase, remove on lapse), so we don't need a Nexus
		   event hook — the membership check is fresh every request.
		   Admin bypass so Derrick can test without a subscription. */
		try
		{
			$member = \IPS\Member::load( (int) ( $row['member_id'] ?? 0 ) );
			$isAdmin = false;
			try { $isAdmin = $member && $member->member_id && method_exists( $member, 'isAdmin' ) && $member->isAdmin(); }
			catch ( \Throwable ) { $isAdmin = false; }

			if ( !$isAdmin )
			{
				$allowedGroups = self::apiAccessGroupIds();
				$inGroup       = false;
				if ( $member && $member->member_id && !empty( $allowedGroups ) )
				{
					try
					{
						if ( method_exists( $member, 'inGroup' ) )
						{
							$inGroup = (bool) $member->inGroup( $allowedGroups );
						}
					}
					catch ( \Throwable ) { $inGroup = false; }

					if ( !$inGroup )
					{
						/* Fallback: manual primary + secondary walk. */
						$memberGroups = [ (int) ( $member->member_group_id ?? 0 ) ];
						foreach ( explode( ',', (string) ( $member->mgroup_others ?? '' ) ) as $g )
						{
							$gi = (int) $g;
							if ( $gi > 0 ) { $memberGroups[] = $gi; }
						}
						$inGroup = (bool) array_intersect( $memberGroups, $allowedGroups );
					}
				}

				if ( !$inGroup )
				{
					$this->respond( [
						'error'         => 'subscription_inactive',
						'message'       => 'Your API subscription is not active. Subscribe or renew to restore access.',
						'subscribe_url' => self::subscribeUrl(),
					], 402 );
					return null;
				}
			}
		}
		catch ( \Throwable )
		{
			/* Rare — Member::load blew up. Fail closed for safety. */
			$this->respond( [
				'error'   => 'subscription_inactive',
				'message' => 'Could not verify subscription state.',
			], 402 );
			return null;
		}

		/* v1.6.31 QUOTA + BURST GATE — enforce tier quota and burst
		   throttle for this key before returning it as authenticated.
		   Sets $this->quotaState so respond() can emit rate-limit
		   headers on the successful response. */
		$this->authedKey = $row;
		$keyId  = (int) $row['id'];
		$quota  = $this->computeQuota( $row );
		$reset  = $this->monthResetTs();
		$period = $this->currentPeriod();

		/* Burst throttle first — cheap 1-second bucket. Admins/unlimited
		   still enforce burst so a rogue admin token can't hammer the
		   DB, but with a much higher default cap. */
		$burstLimit = (int) ( \IPS\Settings::i()->gdcompliance_api_burst_per_sec ?? 10 );
		if ( $burstLimit < 1 ) { $burstLimit = 10; }
		if ( $quota['is_unlimited'] ) { $burstLimit = max( $burstLimit, 60 ); }
		$burstBucket = 'sec:' . time();
		$burstUsed   = $this->readUsage( $keyId, $burstBucket );
		if ( $burstUsed >= $burstLimit )
		{
			$this->quotaState = $this->buildQuotaState( $quota, 0, $reset );
			$this->respond( [
				'error'       => 'rate_limited',
				'message'     => 'Too many requests, slow down.',
				'retry_after' => 1,
			], 429, [ 'Retry-After' => '1' ] );
			return null;
		}

		/* Monthly quota — skipped for unlimited tiers. */
		$monthUsed = $this->readUsage( $keyId, $period );
		if ( !$quota['is_unlimited'] && $monthUsed >= (int) $quota['limit'] )
		{
			$this->quotaState = $this->buildQuotaState( $quota, $monthUsed, $reset );
			$this->respond( [
				'error'         => 'quota_exceeded',
				'message'       => 'Monthly API quota reached. Upgrade or wait for reset.',
				'reset'         => date( 'Y-m-d', $reset ),
				'subscribe_url' => self::subscribeUrl(),
			], 429, [ 'Retry-After' => (string) max( 1, $reset - time() ) ] );
			return null;
		}

		$this->quotaState = $this->buildQuotaState( $quota, $monthUsed, $reset );

		/* Best-effort lifetime metering — never blocks the successful path. */
		try
		{
			\IPS\Db::i()->update(
				'gd_compliance_api_keys',
				[
					'last_used_at'  => time(),
					'request_count' => ( (int) ( $row['request_count'] ?? 0 ) ) + 1,
				],
				[ 'id=?', $keyId ]
			);
		}
		catch ( \Throwable ) {}

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

	/* ==================================================================
	 * v1.6.30 helpers — subscription gate + subscribe URL builder
	 * ================================================================== */

	/**
	 * The parsed list of member group IDs allowed to use the API.
	 * Reads gdcompliance_api_access_groups (comma-separated). Empty
	 * setting → empty list → fail-closed (no member can use the API
	 * except admins).
	 */
	protected static function apiAccessGroupIds(): array
	{
		$raw = (string) ( \IPS\Settings::i()->gdcompliance_api_access_groups ?? '' );
		return array_values( array_filter( array_map( 'intval', explode( ',', $raw ) ) ) );
	}

	/**
	 * URL for the Nexus subscription package that backs API access.
	 * Reads gdcompliance_api_subscription_id. Falls back to the Nexus
	 * subscriptions index if no id is set. Used in the 402 response
	 * payload and on the self-service mykey page.
	 */
	protected static function subscribeUrl(): string
	{
		$id = (int) ( \IPS\Settings::i()->gdcompliance_api_subscription_id ?? 0 );
		try
		{
			if ( $id > 0 )
			{
				return (string) \IPS\Http\Url::internal(
					'app=nexus&module=store&controller=product&id=' . $id, 'front'
				);
			}
			return (string) \IPS\Http\Url::internal(
				'app=nexus&module=subscriptions&controller=subscriptions', 'front', 'nexus_subscriptions'
			);
		}
		catch ( \Throwable ) { return '/'; }
	}

	/**
	 * Return the API-access status of a given member for the mykey
	 * page. Two-value result: 'active' | 'inactive' — used to switch
	 * between the key-management UI and the upsell block.
	 */
	protected static function memberApiStatus( \IPS\Member $member ): string
	{
		if ( !$member->member_id ) { return 'inactive'; }
		try
		{
			if ( method_exists( $member, 'isAdmin' ) && $member->isAdmin() ) { return 'active'; }
		}
		catch ( \Throwable ) {}

		$allowed = self::apiAccessGroupIds();
		if ( empty( $allowed ) ) { return 'inactive'; }
		try
		{
			if ( method_exists( $member, 'inGroup' ) && $member->inGroup( $allowed ) ) { return 'active'; }
		}
		catch ( \Throwable ) {}

		$memberGroups = [ (int) ( $member->member_group_id ?? 0 ) ];
		foreach ( explode( ',', (string) ( $member->mgroup_others ?? '' ) ) as $g )
		{
			$gi = (int) $g;
			if ( $gi > 0 ) { $memberGroups[] = $gi; }
		}
		return array_intersect( $memberGroups, $allowed ) ? 'active' : 'inactive';
	}

	/* ==================================================================
	 * v1.6.30 mykey — self-service API key page (HTML, browser)
	 * ==================================================================
	 *
	 * Endpoint: /api/compliance/mykey (front, theme-wrapped, HTML).
	 * NOT gated by API key — it's a browser action by a logged-in
	 * member managing their OWN key. All state changes CSRF-checked.
	 *
	 * Guest         → login prompt with return URL preserved.
	 * Non-subscribed member → upsell + subscribe link.
	 * Subscribed member     → key management (view / generate /
	 *                          regenerate) + integration snippet.
	 */

	/* ==================================================================
	 * v1.6.36 — API documentation page (HTML, gated)
	 * ==================================================================
	 *
	 * /api/compliance/docs. Guests bounce to login; logged-in members
	 * outside the API-access groups see the subscribe upsell; admins
	 * and API subscribers get the full developer reference. Content
	 * matches the shipped API as of Stage 4 (check + batch + product +
	 * mykey; secret + publishable keys; tier quotas / burst; CORS).
	 */
	public function docs(): void
	{
		$member = \IPS\Member::loggedIn();
		$h      = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$selfUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=api&controller=api&do=docs', 'front'
		);
		$mykeyUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=api&controller=api&do=mykey', 'front'
		);

		\IPS\Output::i()->title      = 'Compliance API — Documentation';
		\IPS\Output::i()->breadcrumb = [];
		\IPS\Output::i()->sidebar    = [ 'enabled' => false ];

		/* Guest → login prompt. */
		if ( !$member->member_id )
		{
			$loginUrl = (string) \IPS\Http\Url::internal(
				'app=core&module=system&controller=login&ref=' . base64_encode( $selfUrl )
			);
			\IPS\Output::i()->output = $this->docsStyles()
				. '<div class="grcd-wrap"><h1>Compliance API — Documentation</h1>'
				. '<div class="grcd-card grcd-card--info">'
				. '<p>Please log in to view the API documentation.</p>'
				. '<a href="' . $h( $loginUrl ) . '" class="grcd-btn">Log in</a>'
				. '</div></div>';
			return;
		}

		/* Non-subscribed → upsell. Admin bypass is inside memberApiStatus. */
		if ( self::memberApiStatus( $member ) !== 'active' )
		{
			$subUrl = self::subscribeUrl();
			\IPS\Output::i()->output = $this->docsStyles()
				. '<div class="grcd-wrap"><h1>Compliance API — Documentation</h1>'
				. '<div class="grcd-card grcd-card--warn">'
				. '<h2>🔒 Documentation is available to API subscribers</h2>'
				. '<p>The Compliance API is a paid product. Once your subscription is active, this page and your API keys become available.</p>'
				. '<a href="' . $h( $subUrl ) . '" class="grcd-btn">View subscription</a>'
				. '</div></div>';
			return;
		}

		/* Full docs. */
		\IPS\Output::i()->output = $this->docsStyles() . $this->docsBody( $mykeyUrl );
	}

	/**
	 * The full docs body. Kept in one method so the entire page reads
	 * top-to-bottom in source. Uses the actual settings for the
	 * disclaimer + verification status so what dealers read matches
	 * their live responses.
	 */
	protected function docsBody( string $mykeyUrl ): string
	{
		$h = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$disclaimer = trim( (string) ( \IPS\Settings::i()->gdcompliance_api_disclaimer ?? '' ) );
		$verified   = (int) ( \IPS\Settings::i()->gdcompliance_api_verified ?? 0 ) === 1
			? 'verified' : 'pending_legal_review';
		$widgetUrl  = 'https://gunrack.deals/applications/gdcompliance/interface/widget/gunrack-compliance.js';

		$exampleCheckJson  = json_encode( [
			'upc'          => '011356670526',
			'state'        => 'IL',
			'status'       => 'restricted',
			'product'      => 'Savage Arms — Stance XR 9mm',
			'restrictions' => [
				[
					'type'         => 'awb',
					'firearm_type' => 'awb_pistol',
					'reason'       => 'Illinois PICA — enumerated assault-weapons list.',
					'citation'     => '720 ILCS 5/24-1.9',
				],
			],
			'advisories'          => [],
			'disclaimer'          => 'This information is provided for general reference and is not legal advice...',
			'verification_status' => 'pending_legal_review',
			'generated_at'        => 1720000000,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$exampleBatchJson = json_encode( [
			'state'   => 'IL',
			'results' => [
				[
					'upc'          => '011356670526',
					'state'        => 'IL',
					'status'       => 'restricted',
					'product'      => 'Savage Arms — Stance XR 9mm',
					'restrictions' => [ [ 'type' => 'awb', 'reason' => '…', 'citation' => '720 ILCS 5/24-1.9' ] ],
					'advisories'   => [],
				],
				[
					'upc'          => '022188879834',
					'state'        => 'IL',
					'status'       => 'available',
					'product'      => 'Ruger — 10/22 Carbine',
					'restrictions' => [],
					'advisories'   => [],
				],
			],
			'disclaimer'          => '...',
			'verification_status' => 'pending_legal_review',
			'generated_at'        => 1720000000,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$exampleProductJson = json_encode( [
			'upc'                    => '011356670526',
			'product'                => 'Savage Arms — Stance XR 9mm',
			'restricted_states'      => [
				[ 'state' => 'CA', 'state_name' => 'California',    'type' => 'capacity', 'reason' => '…', 'citation' => 'PC §16740' ],
				[ 'state' => 'NY', 'state_name' => 'New York',      'type' => 'awb',      'reason' => '…', 'citation' => 'NY Penal §265.00(22)' ],
			],
			'advisory_states'        => [],
			'restricted_state_codes' => [ 'CA','CT','HI','MA','MD','NJ','NY','RI','WA' ],
			'disclaimer'             => '...',
			'verification_status'    => 'pending_legal_review',
			'generated_at'           => 1720000000,
		], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		$errorTable = [
			[ '401', 'invalid_api_key',       'Missing, unknown, or malformed API key. Verify the Authorization: Bearer value.' ],
			[ '401', 'revoked',               'The key was revoked. Generate a new one on the /api/compliance/mykey page.' ],
			[ '402', 'subscription_inactive', 'The key\'s owning member is not currently in an API-access group. Renew the subscription. Response includes subscribe_url.' ],
			[ '403', 'domain_not_allowed',    'Publishable-key request from an origin not in the key\'s allowed_domains. Add the origin (or its apex) on the mykey page.' ],
			[ '429', 'rate_limited',          'Burst throttle: too many requests per second for this key. Response includes Retry-After.' ],
			[ '429', 'quota_exceeded',        'Monthly tier quota reached. Response includes reset (YYYY-MM-01) and subscribe_url.' ],
			[ '400', 'invalid_upc / invalid_state / invalid_upcs / batch_too_large', 'Client-side input validation failed. See message for details.' ],
			[ '200', 'status="unknown"',      'The UPC exists but is not in our compliance database. Not an error — the response envelope is well-formed with restrictions=[] and advisories=[].' ],
		];
		$errorRows = '';
		foreach ( $errorTable as [ $code, $error, $desc ] )
		{
			$errorRows .= '<tr>'
				. '<td><code>' . $h( $code ) . '</code></td>'
				. '<td><code>' . $h( $error ) . '</code></td>'
				. '<td>' . $h( $desc ) . '</td>'
				. '</tr>';
		}

		$toc = ''
			. '<ul class="grcd-toc">'
			. '<li><a href="#overview">Overview</a></li>'
			. '<li><a href="#auth">Authentication</a></li>'
			. '<li><a href="#endpoints">Endpoints</a>'
			. '  <ul>'
			. '    <li><a href="#e-check">GET /check</a></li>'
			. '    <li><a href="#e-batch">POST /batch</a></li>'
			. '    <li><a href="#e-product">GET /product</a></li>'
			. '  </ul>'
			. '</li>'
			. '<li><a href="#rate">Rate limits &amp; quotas</a></li>'
			. '<li><a href="#errors">Errors</a></li>'
			. '<li><a href="#widget">The widget (embed)</a></li>'
			. '<li><a href="#verify">Verification status</a></li>'
			. '</ul>';

		$out = '<div class="grcd-wrap"><h1>Compliance API — Documentation</h1>'
			. '<div class="grcd-toolbar"><a class="grcd-btn grcd-btn--ghost" href="' . $h( $mykeyUrl ) . '">Manage your keys</a></div>'
			. '<div class="grcd-layout">'
			. '<aside class="grcd-side">' . $toc . '</aside>'
			. '<main class="grcd-main">';

		/* --- Overview --- */
		$out .= '<section id="overview" class="grcd-card">'
			. '<h2>Overview</h2>'
			. '<p>The Compliance API answers one question programmatically: <em>is this firearm-related SKU legal to sell into a given US state?</em> It returns per-state verdicts (restricted / available / advisory / unknown) with reasons + citations, sourced from Gun Rack\'s compliance engine.</p>'
			. '<dl class="grcd-kv">'
			. '<dt>Base URL</dt><dd><code>https://gunrack.deals/api/compliance</code></dd>'
			. '<dt>Formats</dt><dd>JSON only. Every response sets <code>Content-Type: application/json</code> and <code>Cache-Control: no-store</code>.</dd>'
			. '<dt>Verification status on this install</dt><dd><strong>' . $h( $verified ) . '</strong>' . ( $verified === 'pending_legal_review' ? ' — treat responses as reference until Gun Rack completes legal review.' : '' ) . '</dd>'
			. '</dl>'
			. ( $disclaimer !== '' ? '<blockquote class="grcd-note">' . $h( $disclaimer ) . '</blockquote>' : '' )
			. '</section>';

		/* --- Authentication --- */
		$out .= '<section id="auth" class="grcd-card">'
			. '<h2>Authentication</h2>'
			. '<p>Two key types are issued per subscribing member:</p>'
			. '<div class="grcd-two">'
			. '<div><h3>Secret key <span class="grcd-pill grcd-pill--secret">gdc_sk_…</span></h3>'
			. '<ul>'
			. '<li>Server-to-server only. <strong>Never</strong> embed in browser JavaScript.</li>'
			. '<li>Not domain-locked.</li>'
			. '<li>Send as <code>Authorization: Bearer &lt;key&gt;</code>.</li>'
			. '</ul></div>'
			. '<div><h3>Publishable key <span class="grcd-pill grcd-pill--public">gdc_pub_…</span></h3>'
			. '<ul>'
			. '<li>Safe to embed in browser JS (used by the widget).</li>'
			. '<li>Domain-locked. Register the origins on the <a href="' . $h( $mykeyUrl ) . '">mykey</a> page — apex domains cover subdomains.</li>'
			. '<li>Requests without a matching <code>Origin</code> (or <code>Referer</code>) return <code>403 domain_not_allowed</code>.</li>'
			. '</ul></div>'
			. '</div>'
			. '<h3>Sending the key</h3>'
			. '<pre class="grcd-pre">Authorization: Bearer gdc_sk_XXXXXXXX...</pre>'
			. '<p>For clients that can\'t set headers, an <code>api_key=</code> query param is accepted as a fallback — this is what the widget uses so it can call cross-origin without preflight complications.</p>'
			. '<p>Generate / regenerate keys and register domains on <a href="' . $h( $mykeyUrl ) . '">/api/compliance/mykey</a>.</p>'
			. '</section>';

		/* --- Endpoints --- */
		$out .= '<section id="endpoints" class="grcd-card">'
			. '<h2>Endpoints</h2>'

			/* check */
			. '<div id="e-check" class="grcd-endpoint">'
			. '<h3><span class="grcd-method grcd-method--get">GET</span> <code>/api/compliance/check</code></h3>'
			. '<p>Single-state verdict for one UPC. Counts as <strong>1</strong> quota unit.</p>'
			. '<h4>Query parameters</h4>'
			. '<table class="grcd-table">'
			. '<thead><tr><th>Name</th><th>Required</th><th>Description</th></tr></thead>'
			. '<tbody>'
			. '<tr><td><code>upc</code></td><td>yes</td><td>UPC as it appears in your catalog. Alphanumeric plus <code>-._/</code>, max 64.</td></tr>'
			. '<tr><td><code>state</code></td><td>yes</td><td>Two-letter US state code (uppercased server-side). 50 states + DC.</td></tr>'
			. '</tbody></table>'
			. '<h4>Example request</h4>'
			. '<pre class="grcd-pre">curl -H "Authorization: Bearer gdc_sk_..." \\'
			. '&#10;  "https://gunrack.deals/api/compliance/check?upc=011356670526&amp;state=IL"</pre>'
			. '<h4>Example response</h4>'
			. '<pre class="grcd-pre">' . $h( $exampleCheckJson ) . '</pre>'
			. '</div>'

			/* batch */
			. '<div id="e-batch" class="grcd-endpoint">'
			. '<h3><span class="grcd-method grcd-method--post">POST</span> <code>/api/compliance/batch</code></h3>'
			. '<p>Verdicts for many UPCs against one state. Each UPC counts as <strong>1</strong> quota unit; hard cap of 200 UPCs per request. Send <code>Content-Type: application/json</code>.</p>'
			. '<h4>Body</h4>'
			. '<pre class="grcd-pre">{ "state": "IL", "upcs": ["011356670526", "022188879834", ...] }</pre>'
			. '<h4>Example request</h4>'
			. '<pre class="grcd-pre">curl -H "Authorization: Bearer gdc_sk_..." \\'
			. '&#10;  -H "Content-Type: application/json" \\'
			. '&#10;  -X POST "https://gunrack.deals/api/compliance/batch" \\'
			. '&#10;  --data \'{"state":"IL","upcs":["011356670526","022188879834"]}\'</pre>'
			. '<h4>Example response</h4>'
			. '<pre class="grcd-pre">' . $h( $exampleBatchJson ) . '</pre>'
			. '</div>'

			/* product */
			. '<div id="e-product" class="grcd-endpoint">'
			. '<h3><span class="grcd-method grcd-method--get">GET</span> <code>/api/compliance/product</code></h3>'
			. '<p>All-states view for one UPC. This is what powers the browser widget. Counts as <strong>1</strong> quota unit.</p>'
			. '<h4>Query parameters</h4>'
			. '<table class="grcd-table">'
			. '<thead><tr><th>Name</th><th>Required</th><th>Description</th></tr></thead>'
			. '<tbody>'
			. '<tr><td><code>upc</code></td><td>yes</td><td>UPC as above.</td></tr>'
			. '</tbody></table>'
			. '<h4>Example request (server side, secret key)</h4>'
			. '<pre class="grcd-pre">curl -H "Authorization: Bearer gdc_sk_..." \\'
			. '&#10;  "https://gunrack.deals/api/compliance/product?upc=011356670526"</pre>'
			. '<h4>Example request (browser widget, publishable key)</h4>'
			. '<p>The widget calls this URL directly from the browser using the <code>api_key</code> param + the browser\'s <code>Origin</code> header. Both are validated server-side.</p>'
			. '<pre class="grcd-pre">fetch("https://gunrack.deals/api/compliance/product?upc=011356670526&amp;api_key=gdc_pub_...", { method: "GET" })'
			. '&#10;  .then(r =&gt; r.json())'
			. '&#10;  .then(d =&gt; console.log(d.restricted_state_codes));</pre>'
			. '<h4>Example response</h4>'
			. '<pre class="grcd-pre">' . $h( $exampleProductJson ) . '</pre>'
			. '</div>'
			. '</section>';

		/* --- Rate limits & quotas --- */
		$out .= '<section id="rate" class="grcd-card">'
			. '<h2>Rate limits &amp; quotas</h2>'
			. '<p>Every request against your key is metered on two axes:</p>'
			. '<ul>'
			. '<li><strong>Monthly quota</strong> — set by your subscription tier (member group). Resets at the start of the next month, UTC. Batch endpoints count each UPC as one unit.</li>'
			. '<li><strong>Burst throttle</strong> — per-second per-key server-protection cap.</li>'
			. '</ul>'
			. '<h3>Rate-limit headers</h3>'
			. '<p>Every response (post-auth) carries the standard trio:</p>'
			. '<pre class="grcd-pre">X-RateLimit-Limit:     10000'
			. '&#10;X-RateLimit-Remaining: 9982'
			. '&#10;X-RateLimit-Reset:     1725148800   # unix ts of next month\'s start</pre>'
			. '<p>Unlimited tiers report <code>unlimited</code> instead of a number. 429 responses also include <code>Retry-After</code> (in seconds).</p>'
			. '<h3>Exceeded responses</h3>'
			. '<pre class="grcd-pre">HTTP/1.1 429 Too Many Requests'
			. '&#10;{ "error": "rate_limited",  "message": "Too many requests, slow down.", "retry_after": 1 }'
			. '&#10;'
			. '&#10;HTTP/1.1 429 Too Many Requests'
			. '&#10;{ "error": "quota_exceeded", "message": "...", "reset": "2026-02-01",'
			. '&#10;  "subscribe_url": "https://gunrack.deals/store/product/6/" }</pre>'
			. '</section>';

		/* --- Errors --- */
		$out .= '<section id="errors" class="grcd-card">'
			. '<h2>Errors</h2>'
			. '<p>The <code>error</code> key in the response body is machine-readable; <code>message</code> is a short human explanation.</p>'
			. '<table class="grcd-table">'
			. '<thead><tr><th>HTTP</th><th>error</th><th>Meaning &amp; recovery</th></tr></thead>'
			. '<tbody>' . $errorRows . '</tbody>'
			. '</table>'
			. '</section>';

		/* --- The widget --- */
		$out .= '<section id="widget" class="grcd-card">'
			. '<h2>The widget (embed)</h2>'
			. '<p>The Gun Rack Compliance widget is a self-contained vanilla-JS script that renders the all-states restriction view on your product pages. It calls <code>/api/compliance/product</code> with your <strong>publishable</strong> key + the shopper\'s browser Origin.</p>'
			. '<h3>Generic embed</h3>'
			. '<pre class="grcd-pre">&lt;div id="gunrack-compliance"'
			. '&#10;     data-upc="PRODUCT_UPC"'
			. '&#10;     data-key="gdc_pub_XXXX"&gt;&lt;/div&gt;'
			. '&#10;&lt;script src="' . $h( $widgetUrl ) . '" async&gt;&lt;/script&gt;</pre>'
			. '<p>Copy-paste snippets for BigCommerce / Shopify / WooCommerce (with your key pre-filled) live on the <a href="' . $h( $mykeyUrl ) . '">mykey</a> page.</p>'
			. '<h3>Behavior</h3>'
			. '<ul>'
			. '<li>Fails <strong>quietly</strong> — a network/auth error renders nothing rather than breaking your page.</li>'
			. '<li>Scoped CSS (all classes prefixed <code>.grc-</code>) so it won\'t collide with your theme.</li>'
			. '<li>Multi-mount safe — you can drop several containers on the same page (variants, related products).</li>'
			. '<li>Optional "check your state" dropdown that persists the shopper\'s pick in <code>localStorage</code>.</li>'
			. '</ul>'
			. '</section>';

		/* --- Verification --- */
		$out .= '<section id="verify" class="grcd-card">'
			. '<h2>Verification status</h2>'
			. '<p>Every response envelope carries <code>verification_status</code>:</p>'
			. '<ul>'
			. '<li><code>pending_legal_review</code> — reference data only. The compliance engine\'s output has NOT been signed off by Gun Rack\'s legal review. Treat it as advisory information for internal use; don\'t auto-block sales solely on this until Gun Rack flips the switch.</li>'
			. '<li><code>verified</code> — Gun Rack\'s legal team has completed review. Safe to drive customer-facing behavior (with the standard disclaimer still shown).</li>'
			. '</ul>'
			. '<p>This install currently reports <strong>' . $h( $verified ) . '</strong>.</p>'
			. '</section>';

		$out .= '</main></div></div>';
		return $out;
	}

	/**
	 * Docs page styles. Scoped .grcd-* so the theme wrapper's rules
	 * don't override ours and we don't touch anything else.
	 */
	protected function docsStyles(): string
	{
		return '<style>'
			. '.grcd-wrap{max-width:1200px;margin:24px auto;padding:0 16px;font-family:\'Inter\',system-ui,-apple-system,sans-serif;color:#0f172a;font-size:14.5px;line-height:1.6}'
			. '.grcd-wrap h1{margin:0 0 12px;font-size:1.8em;color:#0f172a}'
			. '.grcd-toolbar{margin:0 0 16px}'
			. '.grcd-btn{display:inline-block;background:#1e40af;color:#fff;padding:8px 16px;border-radius:8px;font-weight:600;text-decoration:none;border:none;font-size:.9em}'
			. '.grcd-btn:hover{background:#1e3a8a;color:#fff;text-decoration:none}'
			. '.grcd-btn--ghost{background:#fff;color:#1e40af;border:1px solid #cbd5e1}'
			. '.grcd-btn--ghost:hover{background:#f1f5f9;color:#1e40af}'
			. '.grcd-layout{display:grid;grid-template-columns:220px 1fr;gap:24px;align-items:flex-start}'
			. '@media (max-width:900px){.grcd-layout{grid-template-columns:1fr}.grcd-side{position:static}}'
			. '.grcd-side{position:sticky;top:16px}'
			. '.grcd-toc{list-style:none;padding:0;margin:0;font-size:.9em}'
			. '.grcd-toc a{color:#334155;text-decoration:none;display:block;padding:4px 6px;border-radius:6px}'
			. '.grcd-toc a:hover{background:#f1f5f9;color:#0f172a}'
			. '.grcd-toc ul{list-style:none;padding:4px 0 0 14px;margin:0}'
			. '.grcd-main{min-width:0}'
			. '.grcd-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px 22px;margin-bottom:16px}'
			. '.grcd-card--info{background:#eff6ff;border-color:#bfdbfe}'
			. '.grcd-card--warn{background:#fefce8;border-color:#fde68a}'
			. '.grcd-card h2{margin:0 0 12px;font-size:1.25em;color:#0f172a;border-bottom:1px solid #e2e8f0;padding-bottom:6px}'
			. '.grcd-card h3{margin:16px 0 6px;font-size:1.02em;color:#0f172a}'
			. '.grcd-card h4{margin:12px 0 4px;font-size:.9em;color:#334155;text-transform:uppercase;letter-spacing:.04em}'
			. '.grcd-card p{margin:0 0 10px;color:#334155}'
			. '.grcd-card ul{margin:0 0 12px;padding-left:20px;color:#334155}'
			. '.grcd-card li{margin:2px 0}'
			. '.grcd-card code{background:#f1f5f9;padding:1px 6px;border-radius:4px;font-family:ui-monospace,menlo,monospace;font-size:.88em}'
			. '.grcd-pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:8px;font-family:ui-monospace,menlo,monospace;font-size:.82em;overflow-x:auto;white-space:pre;line-height:1.4;margin:0 0 10px}'
			. '.grcd-note{margin:10px 0;padding:10px 12px;background:#fefce8;border:1px solid #fde68a;color:#78350f;border-radius:8px;font-size:.85em;font-style:italic;line-height:1.5}'
			. '.grcd-kv{margin:0;font-size:.95em}'
			. '.grcd-kv dt{display:inline-block;min-width:220px;color:#64748b;padding:2px 0}'
			. '.grcd-kv dd{display:inline;margin:0;padding:2px 0}'
			. '.grcd-kv dd::after{content:"";display:block;height:4px}'
			. '.grcd-two{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px}'
			. '@media (max-width:700px){.grcd-two{grid-template-columns:1fr}}'
			. '.grcd-two > div{background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:14px}'
			. '.grcd-two h3{margin-top:0}'
			. '.grcd-pill{display:inline-block;padding:1px 8px;border-radius:999px;font-size:11px;font-weight:700;letter-spacing:.04em;font-family:ui-monospace,monospace;text-transform:none;vertical-align:middle}'
			. '.grcd-pill--secret{background:#e5e7eb;color:#374151}'
			. '.grcd-pill--public{background:#e0e7ff;color:#3730a3}'
			. '.grcd-method{display:inline-block;padding:2px 10px;border-radius:6px;font-size:.72em;font-weight:800;letter-spacing:.05em;vertical-align:middle;margin-right:6px}'
			. '.grcd-method--get{background:#dbeafe;color:#1e3a8a}'
			. '.grcd-method--post{background:#fef3c7;color:#78350f}'
			. '.grcd-endpoint{border-top:1px solid #e2e8f0;padding-top:12px;margin-top:12px}'
			. '.grcd-endpoint:first-of-type{border-top:none;padding-top:0;margin-top:0}'
			. '.grcd-table{width:100%;border-collapse:collapse;margin:0 0 12px;font-size:.9em}'
			. '.grcd-table th,.grcd-table td{text-align:left;padding:8px 10px;border-bottom:1px solid #e2e8f0;vertical-align:top}'
			. '.grcd-table th{color:#475569;font-size:.75em;text-transform:uppercase;letter-spacing:.05em;background:#f8fafc}'
			. '.grcd-table code{background:#f1f5f9;font-size:.85em}'
			. '</style>';
	}

	public function mykey(): void
	{
		$member = \IPS\Member::loggedIn();
		$h      = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		/* v1.6.37 — preserve embed=1 through the form action so a
		   dashboard-iframe POST comes back with embed still on and
		   mykeyRedirectUrl() keeps the user in the frame. */
		$selfUrl = $this->mykeyRedirectUrl();

		\IPS\Output::i()->title      = 'Your Compliance API Key';
		\IPS\Output::i()->breadcrumb = [];
		\IPS\Output::i()->sidebar    = [ 'enabled' => false ];

		/* Guest → login prompt. */
		if ( !$member->member_id )
		{
			$loginUrl = (string) \IPS\Http\Url::internal(
				'app=core&module=system&controller=login&ref=' . base64_encode( $selfUrl )
			);
			\IPS\Output::i()->output = $this->mykeyStyles()
				. '<div class="gdak-wrap">'
				. '<h1>Your Compliance API Key</h1>'
				. '<div class="gdak-card gdak-card--info">'
				. '<p>Please log in to view or generate your API key.</p>'
				. '<a href="' . $h( $loginUrl ) . '" class="gdak-btn">Log in</a>'
				. '</div></div>';
			return;
		}

		/* POST → generate or regenerate. */
		if ( isset( \IPS\Request::i()->action ) && ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' )
		{
			$this->mykeyAct();
			return;
		}

		/* Not subscribed → upsell. */
		$status = self::memberApiStatus( $member );
		if ( $status !== 'active' )
		{
			$subUrl = self::subscribeUrl();
			\IPS\Output::i()->output = $this->mykeyStyles()
				. '<div class="gdak-wrap">'
				. '<h1>Your Compliance API Key</h1>'
				. '<div class="gdak-card gdak-card--warn">'
				. '<h2>🔒 API access requires a subscription</h2>'
				. '<p>The Compliance API is a subscription-only integration. Once you subscribe, this page will let you generate and manage your API key.</p>'
				. '<a href="' . $h( $subUrl ) . '" class="gdak-btn">View subscription</a>'
				. '</div></div>';
			return;
		}

		/* v1.6.34 — fetch ALL active/suspended keys for this member.
		   May have one secret + one publishable. Legacy rows without a
		   key_type column default to 'secret'. */
		$secretKey      = null;
		$publishableKey = null;
		try
		{
			foreach ( \IPS\Db::i()->select(
				'*', 'gd_compliance_api_keys',
				[ 'member_id=? AND status!=?', (int) $member->member_id, 'revoked' ],
				'id DESC'
			) as $row )
			{
				$type = (string) ( $row['key_type'] ?? 'secret' );
				if ( $type === 'publishable' && $publishableKey === null )
				{
					$publishableKey = $row;
				}
				elseif ( $type !== 'publishable' && $secretKey === null )
				{
					$secretKey = $row;
				}
				if ( $secretKey && $publishableKey ) { break; }
			}
		}
		catch ( \Throwable ) {}

		$csrfKey = (string) \IPS\Session::i()->csrfKey;
		$docsUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=api&controller=api&do=docs', 'front'
		);
		$html    = $this->mykeyStyles()
			. '<div class="gdak-wrap"><h1>Your Compliance API Keys</h1>'
			. '<p style="margin:-6px 0 14px"><a href="' . $h( $docsUrl ) . '" style="color:#1e40af;font-weight:600;text-decoration:none">Read the API docs →</a></p>';

		/* -- Secret key card. -- */
		if ( is_array( $secretKey ) )
		{
			$html .= $this->renderKeyCard( $secretKey, 'secret', $selfUrl, $csrfKey );
		}
		else
		{
			$html .= '<div class="gdak-card">'
				. '<h2>Secret key (server use)</h2>'
				. '<p>The secret key authenticates server-to-server calls. Send it as <code>Authorization: Bearer …</code>. <strong>Do not embed a secret key in browser JavaScript</strong> — use a publishable key for the widget.</p>'
				. '<form method="post" action="' . $h( $selfUrl ) . '">'
				. '<input type="hidden" name="csrfKey" value="' . $h( $csrfKey ) . '">'
				. '<input type="hidden" name="action" value="generate_secret">'
				. '<button type="submit" class="gdak-btn">Generate secret key</button>'
				. '</form>'
				. '</div>';
		}

		/* -- Publishable key card. -- */
		if ( is_array( $publishableKey ) )
		{
			$html .= $this->renderKeyCard( $publishableKey, 'publishable', $selfUrl, $csrfKey );
		}
		else
		{
			$html .= '<div class="gdak-card">'
				. '<h2>Publishable key (browser widget)</h2>'
				. '<p>The publishable key is safe to embed in the widget script on your product pages. It is <strong>domain-locked</strong>: a leaked key won\'t work from any origin except the domains you register below.</p>'
				. '<form method="post" action="' . $h( $selfUrl ) . '">'
				. '<input type="hidden" name="csrfKey" value="' . $h( $csrfKey ) . '">'
				. '<input type="hidden" name="action" value="generate_publishable">'
				. '<label for="gdak-newdomains" style="display:block;margin:8px 0 4px;font-weight:600">Allowed domains</label>'
				. '<textarea id="gdak-newdomains" name="allowed_domains" rows="3" required class="gdak-textarea" placeholder="acmeguns.com&#10;www.acmeguns.com"></textarea>'
				. '<p style="margin:4px 0 10px;font-size:.85em;color:#64748b">One per line (or comma-separated). A registered domain covers its subdomains automatically.</p>'
				. '<button type="submit" class="gdak-btn">Generate publishable key</button>'
				. '</form>'
				. '</div>';
		}

		/* -- Usage panel. Uses whichever key exists (prefer secret for
		     legacy consistency; publishable-only members get theirs). */
		$panelKey = $secretKey ?? $publishableKey;
		if ( is_array( $panelKey ) )
		{
			$quota    = $this->computeQuota( $panelKey );
			$used     = $this->readUsage( (int) $panelKey['id'], $this->currentPeriod() );
			$reset    = $this->monthResetTs();
			$grpId    = $quota['group_id'] ?? null;
			$grpLbl   = $grpId ? ( 'Group #' . (int) $grpId ) : ( $quota['is_unlimited'] ? 'Unlimited' : 'Default' );
			$resetStr = date( 'F j, Y', $reset );

			if ( $quota['is_unlimited'] )
			{
				$html .= '<div class="gdak-card gdak-card--muted">'
					. '<h2>Usage</h2>'
					. '<dl class="gdak-meta">'
					. '<dt>Tier</dt><dd>' . $h( $grpLbl ) . ' (unlimited)</dd>'
					. '<dt>This month</dt><dd>' . number_format( $used ) . ' requests</dd>'
					. '<dt>Reset</dt><dd>' . $h( $resetStr ) . '</dd>'
					. '</dl>'
					. '</div>';
			}
			else
			{
				$limit    = (int) $quota['limit'];
				$pct      = $limit > 0 ? min( 100, (int) round( ( $used / $limit ) * 100 ) ) : 0;
				$barClass = 'gdak-bar__fill';
				if ( $pct >= 90 ) { $barClass .= ' gdak-bar__fill--danger'; }
				elseif ( $pct >= 75 ) { $barClass .= ' gdak-bar__fill--warn'; }

				$upsell = '';
				if ( $pct >= 100 )
				{
					$upsell = '<p class="gdak-hint gdak-hint--danger">Monthly quota reached. Further requests will return 429 until ' . $h( $resetStr ) . '. <a href="' . $h( self::subscribeUrl() ) . '">Upgrade now</a> to continue immediately.</p>';
				}
				elseif ( $pct >= 75 )
				{
					$upsell = '<p class="gdak-hint">Approaching your monthly quota. <a href="' . $h( self::subscribeUrl() ) . '">Upgrade your subscription</a> to raise the cap.</p>';
				}

				$html .= '<div class="gdak-card gdak-card--muted">'
					. '<h2>Usage</h2>'
					. '<dl class="gdak-meta">'
					. '<dt>Tier</dt><dd>' . $h( $grpLbl ) . '</dd>'
					. '<dt>Quota</dt><dd>' . number_format( $limit ) . ' requests / month</dd>'
					. '<dt>This month</dt><dd>' . number_format( $used ) . ' of ' . number_format( $limit ) . ' (' . $pct . '%)</dd>'
					. '<dt>Reset</dt><dd>' . $h( $resetStr ) . '</dd>'
					. '</dl>'
					. '<div class="gdak-bar"><div class="' . $h( $barClass ) . '" style="width:' . (int) $pct . '%"></div></div>'
					. $upsell
					. '</div>';
			}
		}

		/* Integration snippet — always visible for active members. */
		$disclaimer = trim( (string) ( \IPS\Settings::i()->gdcompliance_api_disclaimer ?? '' ) );
		$verified   = (int) ( \IPS\Settings::i()->gdcompliance_api_verified ?? 0 ) === 1
			? 'verified' : 'pending_legal_review';

		$html .= '<div class="gdak-card gdak-card--muted">'
			. '<h2>How to use it (server-to-server)</h2>'
			. '<p>Send your <strong>secret</strong> key in the <code>Authorization</code> header (preferred) or as an <code>api_key</code> query param.</p>'
			. '<pre class="gdak-pre">curl -H "Authorization: Bearer YOUR_SECRET_KEY" \\'
			. '&#10;  "' . $h( (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=api&controller=api&do=check&upc=011356670526&state=IL', 'front' ) ) . '"</pre>'
			. '<h3>Endpoints</h3>'
			. '<ul>'
			. '<li><code>GET /api/compliance/check?upc=UPC&state=XX</code> — single verdict</li>'
			. '<li><code>GET /api/compliance/product?upc=UPC</code> — all-states verdict (widget uses this)</li>'
			. '<li><code>POST /api/compliance/batch</code> body <code>{"state":"XX","upcs":[…]}</code> — up to 200 UPCs. Counts as one quota unit per UPC.</li>'
			. '<li><code>GET /api/compliance</code> — usage manifest</li>'
			. '</ul>'
			. '<h3>Response envelope</h3>'
			. '<p>Every response includes a plain-language <code>disclaimer</code> and a <code>verification_status</code> flag. Current verification status on this install: <strong>' . $h( $verified ) . '</strong>.</p>'
			. ( $disclaimer !== '' ? '<blockquote class="gdak-disclaimer">' . $h( $disclaimer ) . '</blockquote>' : '' )
			. '</div>';

		/* v1.6.35 — dealer install snippets for the browser widget.
		   Rendered only when a publishable key exists (otherwise the
		   snippets would advertise a placeholder key). */
		if ( is_array( $publishableKey ?? null ) )
		{
			$html .= $this->renderInstallSnippets( (string) $publishableKey['api_key'], (string) ( $publishableKey['allowed_domains'] ?? '' ) );
		}

		$html .= '</div>';

		/* v1.6.37 — bare "embed" mode. When ?embed=1 is on the URL
		   (used by the gddealer dashboard's iframe of this page), skip
		   the IPS theme wrapper entirely and stream the mykey styles +
		   body as a standalone HTML document. The iframe then shows
		   only the key-management UI — no site header/footer. Same
		   output otherwise; the standalone /api/compliance/mykey URL
		   is unchanged. */
		$embed = (int) ( \IPS\Request::i()->embed ?? 0 ) === 1;
		if ( $embed )
		{
			$bare = '<!DOCTYPE html><html><head><meta charset="utf-8">'
				. '<meta name="viewport" content="width=device-width, initial-scale=1">'
				. '<title>' . htmlspecialchars( 'Your Compliance API Keys', ENT_QUOTES, 'UTF-8' ) . '</title>'
				/* v1.6.39 — no <base> element here. Default behavior
				   keeps forms + links inside the iframe, which is what
				   we want. The prior v1.6.37 shell retargeted the
				   whole browser to the parent window when a dealer
				   generated a key, kicking them out of the dashboard.
				   Post-save flow is unchanged: mykeyAct →
				   mykeyRedirectUrl preserves embed=1, so the iframe
				   reloads in place. */
				. '</head><body style="margin:0;background:transparent">'
				. $html
				. '</body></html>';

			\IPS\Output::i()->sendOutput(
				$bare, 200, 'text/html',
				[
					/* Explicit SAMEORIGIN so the same-origin iframe from
					   gddealer keeps working even if global policy is
					   set to something stricter later. Does NOT relax
					   framing to other origins. */
					'X-Frame-Options' => 'SAMEORIGIN',
					'Cache-Control'   => 'no-store, no-cache, must-revalidate',
					'Pragma'          => 'no-cache',
				],
				FALSE, FALSE, FALSE
			);
			return;
		}

		\IPS\Output::i()->output = $html;
	}

	/**
	 * v1.6.35 — copy-paste install snippets for the browser widget,
	 * with the dealer's PUBLISHABLE key pre-filled. One tab per
	 * platform: Generic HTML, BigCommerce, Shopify, WooCommerce.
	 * All snippets reference the widget JS at its interface/ URL.
	 */
	protected function renderInstallSnippets( string $pubKey, string $registeredDomains ): string
	{
		$h  = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$widgetUrl = (string) \IPS\Http\Url::internal(
			'applications/gdcompliance/interface/widget/gunrack-compliance.js', 'none'
		);
		if ( $widgetUrl === '' || strpos( $widgetUrl, 'applications/gdcompliance' ) === false )
		{
			/* Fallback: absolute build via base_url. */
			$widgetUrl = rtrim( (string) \IPS\Settings::i()->base_url, '/' )
				. '/applications/gdcompliance/interface/widget/gunrack-compliance.js';
		}

		$domainsHint = trim( $registeredDomains );
		$noDomainsWarn = '';
		if ( $domainsHint === '' )
		{
			$noDomainsWarn = '<p class="gdak-hint gdak-hint--danger">⚠ You have not registered any allowed domains for this publishable key. Add your dealer domain in the "Allowed domains" field above or the widget will return 403.</p>';
		}

		$generic  =
			'<div id="gunrack-compliance"' . "\n"
			. '     data-upc="PRODUCT_UPC"' . "\n"
			. '     data-key="' . $pubKey . '"></div>' . "\n"
			. '<script src="' . $widgetUrl . '" async></script>';

		$bigcommerce =
			'<div id="gunrack-compliance"' . "\n"
			. '     data-upc="{{product.upc}}"' . "\n"
			. '     data-key="' . $pubKey . '"></div>' . "\n"
			. '<script src="' . $widgetUrl . '" async></script>' . "\n"
			. "\n"
			. '<!--' . "\n"
			. '  If product.upc is empty for your catalog, fall back to' . "\n"
			. '  {{product.sku}} — Stencil templates differ by theme.' . "\n"
			. '-->';

		$shopify =
			'<div id="gunrack-compliance"' . "\n"
			. '     data-upc="{{ product.selected_or_first_available_variant.barcode }}"' . "\n"
			. '     data-key="' . $pubKey . '"></div>' . "\n"
			. '<script src="' . $widgetUrl . '" async></script>' . "\n"
			. "\n"
			. '{% comment %}' . "\n"
			. '  Shopify stores the UPC in variant.barcode. If your' . "\n"
			. '  catalog uses SKU as the UPC instead, use product.selected_or_first_available_variant.sku' . "\n"
			. '{% endcomment %}';

		$woo =
			'// functions.php or a mu-plugin.' . "\n"
			. 'add_action( \'woocommerce_single_product_summary\', function () {' . "\n"
			. '    global $product;' . "\n"
			. '    if ( ! $product ) return;' . "\n"
			. '    // WooCommerce stores UPC/EAN as _global_unique_id or a custom meta.' . "\n"
			. '    $upc = get_post_meta( $product->get_id(), \'_global_unique_id\', true );' . "\n"
			. '    if ( ! $upc ) $upc = $product->get_sku();' . "\n"
			. '    if ( ! $upc ) return;' . "\n"
			. '    printf(' . "\n"
			. '        \'<div id="gunrack-compliance" data-upc="%s" data-key="%s"></div>\',' . "\n"
			. '        esc_attr( $upc ),' . "\n"
			. '        \'' . $pubKey . '\'' . "\n"
			. '    );' . "\n"
			. '}, 25 );' . "\n"
			. "\n"
			. 'add_action( \'wp_enqueue_scripts\', function () {' . "\n"
			. '    if ( ! function_exists( \'is_product\' ) || ! is_product() ) return;' . "\n"
			. '    wp_enqueue_script(' . "\n"
			. '        \'gunrack-compliance\',' . "\n"
			. '        \'' . $widgetUrl . '\',' . "\n"
			. '        [],' . "\n"
			. '        null,' . "\n"
			. '        true' . "\n"
			. '    );' . "\n"
			. '} );';

		$tabs = [
			'generic'     => [ 'Generic HTML',   $generic,     'Paste on the product page template. Replace <code>PRODUCT_UPC</code> with your platform\'s UPC variable.' ],
			'bigcommerce' => [ 'BigCommerce',    $bigcommerce, 'Add to your product page template (Stencil). Verify <code>{{product.upc}}</code> exists in your theme; if not, use <code>{{product.sku}}</code>.' ],
			'shopify'     => [ 'Shopify',        $shopify,     'Add to <code>product-template.liquid</code> (or the section your theme uses). The UPC is in <code>variant.barcode</code>.' ],
			'woocommerce' => [ 'WooCommerce',    $woo,         'Add to <code>functions.php</code> or a mu-plugin. The Woo hook prints the container inside the product summary and enqueues the script only on product pages.' ],
		];

		$navHtml  = '<div class="gdak-tabs">';
		$paneHtml = '';
		$first    = true;
		foreach ( $tabs as $key => [ $label, $body, $note ] )
		{
			$active     = $first ? ' gdak-tab--active' : '';
			$paneActive = $first ? ' gdak-pane--active' : '';
			$navHtml   .= '<button type="button" class="gdak-tab' . $active . '" data-target="gdak-pane-' . $h( $key ) . '">' . $h( $label ) . '</button>';
			$paneHtml  .= '<div class="gdak-pane' . $paneActive . '" id="gdak-pane-' . $h( $key ) . '">'
				. '<p class="gdak-snippet-note">' . $note . '</p>'
				. '<pre class="gdak-pre gdak-snippet"><code>' . $h( $body ) . '</code></pre>'
				. '<button type="button" class="gdak-btn gdak-btn--sm gdak-copy-btn" data-copy-target="gdak-pane-' . $h( $key ) . '">Copy</button>'
				. '</div>';
			$first = false;
		}
		$navHtml .= '</div>';

		$js = '<script>(function(){'
			. 'var doc=document;'
			. 'doc.addEventListener("click",function(ev){'
			. 'var t=ev.target;'
			. 'if(t.classList && t.classList.contains("gdak-tab")){'
			. ' var tgt=t.getAttribute("data-target");'
			. ' var tabs=t.parentNode.querySelectorAll(".gdak-tab");'
			. ' for(var i=0;i<tabs.length;i++)tabs[i].classList.remove("gdak-tab--active");'
			. ' t.classList.add("gdak-tab--active");'
			. ' var panes=doc.querySelectorAll(".gdak-pane");'
			. ' for(var j=0;j<panes.length;j++){panes[j].classList.remove("gdak-pane--active");}'
			. ' var pane=doc.getElementById(tgt);'
			. ' if(pane)pane.classList.add("gdak-pane--active");'
			. '}'
			. 'if(t.classList && t.classList.contains("gdak-copy-btn")){'
			. ' var pt=t.getAttribute("data-copy-target");'
			. ' var pane=doc.getElementById(pt);'
			. ' if(!pane)return;'
			. ' var code=pane.querySelector(".gdak-snippet code");'
			. ' if(!code)return;'
			. ' var text=code.innerText||code.textContent||"";'
			. ' try{navigator.clipboard.writeText(text);t.textContent="Copied ✓";setTimeout(function(){t.textContent="Copy";},1500);}'
			. ' catch(e){var ta=doc.createElement("textarea");ta.value=text;doc.body.appendChild(ta);ta.select();try{doc.execCommand("copy");t.textContent="Copied ✓";setTimeout(function(){t.textContent="Copy";},1500);}catch(er){}doc.body.removeChild(ta);}'
			. '}'
			. '});'
			. '}());</script>';

		return '<div class="gdak-card gdak-card--muted">'
			. '<h2>Install the widget on your product pages</h2>'
			. '<p>The snippets below have your <strong>publishable</strong> key pre-filled. The widget calls the <code>/api/compliance/product</code> endpoint from the browser and renders the all-states restriction list on your product page.</p>'
			. $noDomainsWarn
			. $navHtml
			. $paneHtml
			. $js
			. '</div>';
	}

	/**
	 * Render a single key card (secret OR publishable) on the mykey
	 * page. Publishable cards also include the domain-edit form.
	 */
	protected function renderKeyCard( array $key, string $kind, string $selfUrl, string $csrfKey ): string
	{
		$h      = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );
		$keyStr = (string) ( $key['api_key']      ?? '' );
		$rc     = (int)    ( $key['request_count'] ?? 0 );
		/* v1.6.41 — IPS DateTime renders in the viewing member's
		   timezone (raw PHP date() uses the server timezone = UTC,
		   so Central-time dealers saw tomorrow's date after ~6pm
		   local). Timestamps stay stored as UTC unix time; only
		   the display localizes. Wrapped in try so a rare
		   DateTime construction failure falls back to a dash
		   rather than blowing up the page. */
		$lu    = (int) ( $key['last_used_at'] ?? 0 );
		$luStr = 'never';
		if ( $lu > 0 )
		{
			try { $luStr = (string) \IPS\DateTime::ts( $lu ); }
			catch ( \Throwable ) { $luStr = '—'; }
		}
		$ca    = (int) ( $key['created_at']   ?? 0 );
		$caStr = '—';
		if ( $ca > 0 )
		{
			try { $caStr = (string) \IPS\DateTime::ts( $ca )->localeDate(); }
			catch ( \Throwable ) { $caStr = '—'; }
		}

		$isPub    = ( $kind === 'publishable' );
		$title    = $isPub ? 'Publishable key (browser widget)' : 'Secret key (server use)';
		$typeHint = $isPub
			? 'Safe to embed in browser JS. Domain-locked to the origins listed below.'
			: 'For server-to-server calls. Send as Authorization: Bearer …';
		$regenAct = $isPub ? 'regenerate_publishable' : 'regenerate_secret';

		$out = '<div class="gdak-card">'
			. '<h2>' . $h( $title ) . '</h2>'
			. '<p style="margin:0 0 10px;font-size:.9em;color:#475569">' . $h( $typeHint ) . '</p>'
			. '<code class="gdak-key">' . $h( $keyStr ) . '</code>'
			. '<dl class="gdak-meta">'
			. '<dt>Created</dt><dd>' . $h( $caStr ) . '</dd>'
			. '<dt>Last used</dt><dd>' . $h( $luStr ) . '</dd>'
			. '<dt>Lifetime</dt><dd>' . number_format( $rc ) . ' requests</dd>'
			. '</dl>';

		if ( $isPub )
		{
			$domains = (string) ( $key['allowed_domains'] ?? '' );
			$out .= '<form method="post" action="' . $h( $selfUrl ) . '" style="margin-top:14px">'
				. '<input type="hidden" name="csrfKey" value="' . $h( $csrfKey ) . '">'
				. '<input type="hidden" name="action" value="save_domains">'
				. '<label for="gdak-domains" style="display:block;margin:0 0 4px;font-weight:600">Allowed domains</label>'
				. '<textarea id="gdak-domains" name="allowed_domains" rows="3" class="gdak-textarea">' . $h( $domains ) . '</textarea>'
				. '<p style="margin:4px 0 8px;font-size:.85em;color:#64748b">One per line (or comma-separated). A registered domain covers its subdomains.</p>'
				. '<button type="submit" class="gdak-btn">Save domains</button>'
				. '</form>';
		}

		$out .= '<form method="post" action="' . $h( $selfUrl ) . '" onsubmit="return confirm(\'Regenerating will invalidate the existing key immediately. Any live integrations using it will break until you paste in the new key. Continue?\');" style="margin-top:14px">'
			. '<input type="hidden" name="csrfKey" value="' . $h( $csrfKey ) . '">'
			. '<input type="hidden" name="action" value="' . $h( $regenAct ) . '">'
			. '<button type="submit" class="gdak-btn gdak-btn--warn">Regenerate ' . ( $isPub ? 'publishable' : 'secret' ) . ' key</button>'
			. '</form>'
			. '</div>';

		return $out;
	}

	/**
	 * Handle the POST from the mykey page. CSRF-checked; only lets
	 * the member touch their OWN row.
	 *
	 * Actions:
	 *   generate_secret / regenerate_secret          — Stage-1 secret key
	 *   generate_publishable / regenerate_publishable — v1.6.34 publishable key
	 *   save_domains                                  — update publishable key's
	 *                                                    allowed_domains
	 *
	 * Regenerate revokes ONLY existing keys of the same TYPE so a
	 * dealer with both key types keeps the other one alive.
	 */
	/**
	 * v1.6.37 — return the mykey URL, preserving the embed=1 flag if
	 * the current request had it. Used by mykeyAct() so a form
	 * submitted inside the dashboard iframe stays inside the iframe
	 * after redirect.
	 */
	protected function mykeyRedirectUrl(): string
	{
		$q = 'app=gdcompliance&module=api&controller=api&do=mykey';
		if ( (int) ( \IPS\Request::i()->embed ?? 0 ) === 1 )
		{
			$q .= '&embed=1';
		}
		return (string) \IPS\Http\Url::internal( $q, 'front' );
	}

	protected function mykeyAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( $this->mykeyRedirectUrl() );
			return;
		}
		if ( self::memberApiStatus( $member ) !== 'active' )
		{
			\IPS\Output::i()->redirect( $this->mykeyRedirectUrl() );
			return;
		}

		$action = (string) ( \IPS\Request::i()->action ?? '' );

		/* ---- v1.6.34 dispatch by action ---- */

		if ( $action === 'save_domains' )
		{
			$domainsRaw = trim( substr( (string) ( \IPS\Request::i()->allowed_domains ?? '' ), 0, 4000 ) );
			$sanitized  = $this->sanitizeDomains( $domainsRaw );
			try
			{
				\IPS\Db::i()->update(
					'gd_compliance_api_keys',
					[ 'allowed_domains' => $sanitized ],
					[ 'member_id=? AND key_type=? AND status=?', (int) $member->member_id, 'publishable', 'active' ]
				);
			}
			catch ( \Throwable ) {}
			\IPS\Output::i()->redirect( $this->mykeyRedirectUrl() );
			return;
		}

		$genPub = ( $action === 'generate_publishable' || $action === 'regenerate_publishable' );
		$isRegen = ( $action === 'regenerate_secret' || $action === 'regenerate_publishable' );
		$keyType = $genPub ? 'publishable' : 'secret';

		$prefix = $genPub ? 'gdc_pub_' : 'gdc_sk_';
		try { $newKey = $prefix . bin2hex( random_bytes( 20 ) ); }
		catch ( \Throwable ) { $newKey = ''; }
		if ( $newKey === '' )
		{
			\IPS\Output::i()->error( 'Could not generate a secure key.', '2GDMK/1', 500 );
			return;
		}

		$domains = null;
		if ( $genPub )
		{
			$domainsRaw = trim( substr( (string) ( \IPS\Request::i()->allowed_domains ?? '' ), 0, 4000 ) );
			$domains    = $this->sanitizeDomains( $domainsRaw );
			if ( $action === 'generate_publishable' && $domains === '' )
			{
				\IPS\Output::i()->error( 'A publishable key requires at least one allowed domain.', '2GDMK/3', 400 );
				return;
			}
			/* Regenerate keeps existing domains if the form didn't send them. */
			if ( $action === 'regenerate_publishable' && $domains === '' )
			{
				try
				{
					$existing = \IPS\Db::i()->select(
						'allowed_domains', 'gd_compliance_api_keys',
						[ 'member_id=? AND key_type=? AND status=?', (int) $member->member_id, 'publishable', 'active' ],
						'id DESC', 1
					)->first();
					if ( is_string( $existing ) ) { $domains = $existing; }
				}
				catch ( \Throwable ) {}
			}
		}

		if ( $isRegen )
		{
			try
			{
				\IPS\Db::i()->update(
					'gd_compliance_api_keys',
					[ 'status' => 'revoked' ],
					[ 'member_id=? AND key_type=? AND status!=?', (int) $member->member_id, $keyType, 'revoked' ]
				);
			}
			catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Db::i()->insert( 'gd_compliance_api_keys', [
				'api_key'         => $newKey,
				'member_id'       => (int) $member->member_id,
				'label'           => 'Self-service ' . $keyType . ' (' . substr( (string) $member->name, 0, 50 ) . ')',
				'status'          => 'active',
				'created_at'      => time(),
				'last_used_at'    => null,
				'request_count'   => 0,
				'key_type'        => $keyType,
				'allowed_domains' => $domains,
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'mykeyAct insert: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'Could not store the new key.', '2GDMK/2', 500 );
			return;
		}

		\IPS\Output::i()->redirect( $this->mykeyRedirectUrl() );
	}

	/**
	 * Normalize a whitespace/comma-separated list of domains into a
	 * canonical "one per line, lowercase, no scheme, no path" form.
	 * Rejects entries that don't look like a hostname (letters,
	 * digits, dots, hyphens).
	 */
	protected function sanitizeDomains( string $raw ): string
	{
		$raw = strtolower( trim( $raw ) );
		if ( $raw === '' ) { return ''; }
		$parts = preg_split( '/[\s,]+/', $raw );
		if ( !is_array( $parts ) ) { return ''; }
		$out = [];
		foreach ( $parts as $p )
		{
			$p = trim( (string) $p );
			if ( $p === '' ) { continue; }
			$p = (string) preg_replace( '#^https?://#', '', $p );
			$p = rtrim( $p, '/' );
			if ( $p === '' ) { continue; }
			if ( preg_match( '/^[a-z0-9][a-z0-9.\-]*[a-z0-9]$/', $p ) || $p === 'localhost' )
			{
				$out[] = $p;
			}
		}
		return implode( "\n", array_values( array_unique( $out ) ) );
	}

	/**
	 * Inline styles for the mykey page. Kept in one place; reused
	 * across all three visitor states (guest / non-sub / active).
	 */
	protected function mykeyStyles(): string
	{
		return '<style>'
			. '.gdak-wrap{max-width:820px;margin:24px auto;padding:0 16px;font-family:\'Inter\',system-ui,-apple-system,sans-serif;color:#0f172a}'
			. '.gdak-wrap h1{margin:0 0 18px;font-size:1.6em;color:#0f172a}'
			. '.gdak-card{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:20px 22px;margin-bottom:16px}'
			. '.gdak-card h2{margin:0 0 10px;font-size:1.15em;color:#0f172a}'
			. '.gdak-card h3{margin:16px 0 6px;font-size:.95em;color:#334155}'
			. '.gdak-card p{margin:0 0 12px;color:#475569;line-height:1.5}'
			. '.gdak-card--info{background:#eff6ff;border-color:#bfdbfe}'
			. '.gdak-card--warn{background:#fefce8;border-color:#fde68a}'
			. '.gdak-card--muted{background:#f8fafc}'
			. '.gdak-btn{display:inline-block;background:#1e40af;color:#fff;padding:9px 18px;border-radius:8px;font-weight:600;text-decoration:none;border:none;cursor:pointer;font-size:.95em}'
			. '.gdak-btn:hover{background:#1e3a8a;color:#fff;text-decoration:none}'
			. '.gdak-btn--warn{background:#b91c1c}'
			. '.gdak-btn--warn:hover{background:#991b1b}'
			. '.gdak-key{display:block;background:#0f172a !important;color:#f8fafc !important;padding:12px 14px;border-radius:8px;font-family:ui-monospace,menlo,monospace;font-size:.95em;word-break:break-all;margin-bottom:14px}'
			. '.gdak-meta{margin:0;font-size:.9em;color:#475569}'
			. '.gdak-meta dt{display:inline-block;width:110px;color:#64748b}'
			. '.gdak-meta dd{display:inline;margin:0}'
			. '.gdak-meta dd::after{content:"";display:block;height:6px}'
			. '.gdak-pre{background:#0f172a;color:#e2e8f0;padding:12px 14px;border-radius:8px;font-family:ui-monospace,menlo,monospace;font-size:.85em;overflow-x:auto;white-space:pre;line-height:1.4}'
			. '.gdak-disclaimer{margin:10px 0 0;padding:10px 12px;background:#fefce8;border:1px solid #fde68a;border-radius:8px;font-size:.85em;color:#78350f;line-height:1.5;font-style:italic}'
			. '.gdak-card ul{margin:0 0 12px;padding-left:20px;color:#334155;line-height:1.7}'
			. '.gdak-card code{background:#f1f5f9;padding:1px 6px;border-radius:4px;font-family:ui-monospace,monospace;font-size:.9em}'
			/* v1.6.31 usage progress bar */
			. '.gdak-bar{background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;margin:6px 0 10px}'
			. '.gdak-bar__fill{background:#1e40af;height:100%;transition:width .3s ease}'
			. '.gdak-bar__fill--warn{background:#d97706}'
			. '.gdak-bar__fill--danger{background:#b91c1c}'
			. '.gdak-hint{margin:8px 0 0;padding:8px 10px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:6px;font-size:.85em;color:#1e3a8a}'
			. '.gdak-hint a{color:#1e40af;font-weight:600}'
			. '.gdak-hint--danger{background:#fee2e2;border-color:#fecaca;color:#7f1d1d}'
			. '.gdak-hint--danger a{color:#7f1d1d}'
			. '.gdak-textarea{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:8px;font-family:ui-monospace,monospace;font-size:.9em;color:#0f172a;resize:vertical;box-sizing:border-box}'
			. '.gdak-textarea:focus{outline:none;border-color:#3b82f6;box-shadow:0 0 0 3px rgba(59,130,246,.15)}'
			/* v1.6.35 install-snippet tabs */
			. '.gdak-tabs{display:flex;gap:2px;background:#f1f5f9;border-radius:8px;padding:4px;margin:12px 0}'
			. '.gdak-tab{background:transparent;border:none;padding:8px 14px;font-weight:600;font-size:.85em;color:#334155;border-radius:6px;cursor:pointer;flex:1 1 auto}'
			. '.gdak-tab:hover{background:rgba(255,255,255,.6)}'
			. '.gdak-tab--active{background:#fff;color:#1e40af;box-shadow:0 1px 3px rgba(15,23,42,.06)}'
			. '.gdak-pane{display:none;margin-top:4px}'
			. '.gdak-pane--active{display:block}'
			. '.gdak-snippet-note{margin:0 0 10px;font-size:.85em;color:#475569;line-height:1.5}'
			. '.gdak-snippet{max-height:340px;overflow:auto}'
			. '.gdak-snippet code{background:transparent;color:inherit;font-family:inherit;padding:0}'
			. '.gdak-btn--sm{padding:6px 14px;font-size:.8em;margin-top:6px}'
			. '.gdak-copy-btn{background:#1e40af;color:#fff}'
			. '.gdak-copy-btn:hover{background:#1e3a8a}'
			. '</style>';
	}

	/* ==================================================================
	 * v1.6.31 tier + quota + usage metering
	 * ================================================================== */

	/** Current-month period key. */
	protected function currentPeriod(): string
	{
		return date( 'Y-m' );
	}

	/**
	 * Unix ts of the first second of NEXT month — used as the
	 * rate-limit reset epoch on every response header.
	 */
	protected function monthResetTs(): int
	{
		$firstOfNext = strtotime( 'first day of next month 00:00:00' );
		return (int) ( $firstOfNext ?: ( time() + 86400 * 30 ) );
	}

	/**
	 * Parse gdcompliance_api_tiers into a group_id → quota map.
	 * Format: JSON object with string/int keys → int values, e.g.
	 *   {"13": 10000, "14": 100000}
	 * A quota of 0 means "unlimited" for that tier. Malformed JSON
	 * returns empty map (fall through to default_quota).
	 */
	protected static function parseTiers(): array
	{
		$raw = (string) ( \IPS\Settings::i()->gdcompliance_api_tiers ?? '' );
		$out = [];
		if ( $raw === '' ) { return $out; }
		try
		{
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) )
			{
				foreach ( $decoded as $g => $q )
				{
					$gi = (int) $g;
					if ( $gi > 0 ) { $out[ $gi ] = (int) $q; }
				}
			}
		}
		catch ( \Throwable ) {}
		return $out;
	}

	/**
	 * Compute the quota for a given key row.
	 *   [ 'is_unlimited' => bool, 'limit' => int|null, 'group_id' => int|null ]
	 *
	 * Rules:
	 *   - Admin owner → unlimited.
	 *   - Highest-quota tier among the member's groups WINS (so
	 *     upgrading to a bigger tier bumps the limit immediately).
	 *   - Tier quota of 0 means unlimited for that tier.
	 *   - Group in api_access_groups but not in tiers map → default_quota.
	 *   - Not in any API group → 0 quota (but Stage-2 gate already
	 *     rejected them, so this branch is only reachable if the
	 *     setting is misconfigured).
	 */
	protected function computeQuota( array $keyRow ): array
	{
		try
		{
			$member = \IPS\Member::load( (int) ( $keyRow['member_id'] ?? 0 ) );
		}
		catch ( \Throwable ) { $member = null; }

		if ( $member && $member->member_id )
		{
			try
			{
				if ( method_exists( $member, 'isAdmin' ) && $member->isAdmin() )
				{
					return [ 'is_unlimited' => true, 'limit' => null, 'group_id' => null ];
				}
			}
			catch ( \Throwable ) {}

			$memberGroups = [ (int) ( $member->member_group_id ?? 0 ) ];
			foreach ( explode( ',', (string) ( $member->mgroup_others ?? '' ) ) as $g )
			{
				$gi = (int) $g;
				if ( $gi > 0 ) { $memberGroups[] = $gi; }
			}
			$memberGroups = array_values( array_unique( $memberGroups ) );

			$tiers   = self::parseTiers();
			$allowed = self::apiAccessGroupIds();
			$best    = null;    /* highest observed quota */
			$bestGrp = null;

			foreach ( $memberGroups as $gid )
			{
				if ( !in_array( $gid, $allowed, true ) ) { continue; }
				$q = $tiers[ $gid ] ?? null;
				if ( $q === 0 )
				{
					return [ 'is_unlimited' => true, 'limit' => null, 'group_id' => $gid ];
				}
				if ( $q === null )
				{
					$q = (int) ( \IPS\Settings::i()->gdcompliance_api_default_quota ?? 10000 );
					if ( $q < 1 ) { $q = 10000; }
				}
				if ( $best === null || $q > $best ) { $best = $q; $bestGrp = $gid; }
			}

			if ( $best !== null )
			{
				return [ 'is_unlimited' => false, 'limit' => (int) $best, 'group_id' => $bestGrp ];
			}
		}

		/* Fallback: use the default quota. Reachable only if Stage-2
		   gate is bypassed (admin misconfiguration). */
		$q = (int) ( \IPS\Settings::i()->gdcompliance_api_default_quota ?? 10000 );
		if ( $q < 1 ) { $q = 10000; }
		return [ 'is_unlimited' => false, 'limit' => $q, 'group_id' => null ];
	}

	/**
	 * Pack the pieces respond() needs into one array shape.
	 */
	protected function buildQuotaState( array $quota, int $used, int $reset ): array
	{
		if ( $quota['is_unlimited'] )
		{
			return [ 'is_unlimited' => true, 'limit' => null, 'used' => $used, 'remaining' => null, 'reset' => $reset ];
		}
		$limit     = (int) $quota['limit'];
		$remaining = max( 0, $limit - $used );
		return [ 'is_unlimited' => false, 'limit' => $limit, 'used' => $used, 'remaining' => $remaining, 'reset' => $reset ];
	}

	/**
	 * Read the current count for a (key_id, period) row. Zero if the
	 * row doesn't exist yet. Never throws.
	 */
	protected function readUsage( int $keyId, string $period ): int
	{
		try
		{
			return (int) \IPS\Db::i()->select(
				'count', 'gd_compliance_api_usage',
				[ 'key_id=? AND period=?', $keyId, $period ]
			)->first();
		}
		catch ( \Throwable ) { return 0; }
	}

	/**
	 * Increment BOTH the monthly bucket and the current-second burst
	 * bucket by $units. Best-effort — a DB failure never fails the
	 * user's request (Stage-3 metering is a cross-cutting concern,
	 * not the API contract).
	 *
	 * Also opportunistically expires any burst rows older than 5
	 * seconds so the table doesn't grow forever. Cheap DELETE.
	 */
	protected function incrementUsage( int $keyId, int $units = 1 ): void
	{
		if ( $units < 1 ) { return; }
		$period = $this->currentPeriod();
		$second = 'sec:' . time();

		foreach ( [ $period => $units, $second => $units ] as $p => $u )
		{
			try
			{
				\IPS\Db::i()->preparedQuery(
					'INSERT INTO ' . \IPS\Db::i()->prefix . 'gd_compliance_api_usage
					   (key_id, period, count) VALUES (?, ?, ?)
					 ON DUPLICATE KEY UPDATE count = count + VALUES(count)',
					[ $keyId, $p, $u ]
				);
			}
			catch ( \Throwable ) {}
		}

		/* Opportunistic cleanup — chance-gated to keep it lightweight.
		   1 in ~50 requests runs the DELETE; older-than-5-seconds
		   burst rows are dropped. Uses time()-based bucket instead of
		   random() to stay deterministic-in-test. */
		if ( ( time() % 50 ) === 0 )
		{
			try
			{
				$cutoff = 'sec:' . ( time() - 5 );
				\IPS\Db::i()->delete(
					'gd_compliance_api_usage',
					[ "period LIKE 'sec:%' AND period < ?", $cutoff ]
				);
			}
			catch ( \Throwable ) {}
		}
	}

	/**
	 * Called by check()/batch() to charge $units against the caller's
	 * quota. Also updates $this->quotaState so the outgoing
	 * X-RateLimit-Remaining header reflects the post-increment total
	 * (standard API convention: the header describes what's left
	 * AFTER this call).
	 */
	protected function accrue( int $units ): void
	{
		if ( $units < 1 || $this->authedKey === null ) { return; }
		$this->incrementUsage( (int) $this->authedKey['id'], $units );

		if ( is_array( $this->quotaState ) )
		{
			$this->quotaState['used'] = (int) ( $this->quotaState['used'] ?? 0 ) + $units;
			if ( !empty( $this->quotaState['is_unlimited'] ) ) { return; }
			$this->quotaState['remaining'] = max( 0,
				(int) $this->quotaState['limit'] - (int) $this->quotaState['used']
			);
		}
	}

	/* ==================================================================
	 * v1.6.34 — publishable-key domain lock + CORS
	 * ================================================================== */

	/**
	 * Extract the request's origin as a full "scheme://host" string.
	 * Prefer the Origin header (sent by browsers on cross-origin
	 * requests); fall back to Referer's scheme+host when Origin is
	 * absent. Empty string when neither is present or the URL can't
	 * be parsed — that means a non-browser caller and (for a
	 * publishable key) the auth gate rejects them.
	 */
	protected function requestOrigin(): string
	{
		$origin = trim( (string) ( $_SERVER['HTTP_ORIGIN'] ?? '' ) );
		if ( $origin !== '' && preg_match( '~^https?://[^/]+~i', $origin ) )
		{
			return $origin;
		}
		$referer = trim( (string) ( $_SERVER['HTTP_REFERER'] ?? '' ) );
		if ( $referer !== '' )
		{
			$scheme = parse_url( $referer, PHP_URL_SCHEME );
			$host   = parse_url( $referer, PHP_URL_HOST );
			if ( $scheme && $host )
			{
				return strtolower( $scheme ) . '://' . strtolower( $host );
			}
		}
		return '';
	}

	/**
	 * Domain match: is $origin's host allowed by the key's
	 * comma/newline-separated $allowedRaw list?
	 *
	 * Match rules:
	 *   - Case-insensitive.
	 *   - Ignore any scheme or trailing slash in the allowed entry.
	 *   - Exact host match (foo.com == foo.com).
	 *   - Registered-domain match: an allowed entry of "foo.com"
	 *     also matches "www.foo.com" and "shop.foo.com" (any
	 *     subdomain), so a dealer can register their apex domain
	 *     once and cover subdomains.
	 *   - Localhost / IP literals only match exactly.
	 */
	protected function originMatchesKey( string $origin, string $allowedRaw ): bool
	{
		$host = strtolower( (string) ( parse_url( $origin, PHP_URL_HOST ) ?: '' ) );
		if ( $host === '' ) { return false; }

		$allowedRaw = strtolower( trim( $allowedRaw ) );
		if ( $allowedRaw === '' ) { return false; }
		$parts = preg_split( '/[\s,]+/', $allowedRaw );
		if ( !is_array( $parts ) ) { return false; }

		foreach ( $parts as $entry )
		{
			$entry = trim( (string) $entry );
			if ( $entry === '' ) { continue; }
			/* Normalize: strip scheme, strip trailing slash. */
			$entry = (string) preg_replace( '#^https?://#', '', $entry );
			$entry = rtrim( $entry, '/' );
			if ( $entry === '' ) { continue; }

			if ( $entry === $host ) { return true; }
			/* Subdomain match: host ends with ".{entry}". Only for
			   dotted entries — an entry of "localhost" matches
			   only "localhost". */
			if ( strpos( $entry, '.' ) !== false
			  && strlen( $host ) > strlen( $entry ) + 1
			  && substr( $host, - ( strlen( $entry ) + 1 ) ) === '.' . $entry )
			{
				return true;
			}
		}
		return false;
	}

	/**
	 * Response CORS headers. Only populated when authenticate() set
	 * $this->allowedOrigin (publishable-key requests). For secret-key
	 * server-to-server calls, no CORS headers — cross-origin isn't
	 * a factor there.
	 */
	protected function corsHeaders(): array
	{
		if ( $this->allowedOrigin === '' ) { return []; }
		return [
			'Access-Control-Allow-Origin' => $this->allowedOrigin,
			'Vary'                        => 'Origin',
		];
	}

	/**
	 * Build the standard X-RateLimit-* headers from $this->quotaState.
	 * Returns an empty array if no quota was tracked (pre-auth error).
	 */
	protected function rateLimitHeaders(): array
	{
		$s = $this->quotaState;
		if ( !is_array( $s ) ) { return []; }
		if ( !empty( $s['is_unlimited'] ) )
		{
			return [
				'X-RateLimit-Limit'     => 'unlimited',
				'X-RateLimit-Remaining' => 'unlimited',
				'X-RateLimit-Reset'     => (string) (int) $s['reset'],
			];
		}
		return [
			'X-RateLimit-Limit'     => (string) (int) ( $s['limit']     ?? 0 ),
			'X-RateLimit-Remaining' => (string) (int) ( $s['remaining'] ?? 0 ),
			'X-RateLimit-Reset'     => (string) (int) ( $s['reset']     ?? 0 ),
		];
	}

	/**
	 * Terminate the request with a JSON body + status code. Uses IPS's
	 * sendOutput() so headers land cleanly and no theme wrapper is
	 * injected. no-store because verdicts should never be cached by
	 * proxies (state law changes daily; false-negative caching is a
	 * liability).
	 *
	 * v1.6.31: rate-limit headers ($this->quotaState) merged into
	 * every post-auth response — success, 429, or otherwise — plus
	 * any caller-provided $extraHeaders (Retry-After on 429).
	 */
	protected function respond( array $payload, int $status = 200, array $extraHeaders = [] ): void
	{
		$headers = array_merge(
			[
				'Cache-Control' => 'no-store, no-cache, must-revalidate',
				'Pragma'        => 'no-cache',
			],
			$this->rateLimitHeaders(),
			$this->corsHeaders(),
			$extraHeaders
		);

		\IPS\Output::i()->sendOutput(
			(string) json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			$status,
			'application/json',
			$headers,
			FALSE,
			FALSE,
			FALSE
		);
	}
}

class api extends _api {}
