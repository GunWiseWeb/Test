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

		$this->respond( [
			'name'      => 'gunrack-compliance-api',
			'version'   => 1,
			'endpoints' => [
				'check' => '/api/compliance/check?upc=UPC&state=XX',
				'batch' => 'POST /api/compliance/batch  body:{"state":"XX","upcs":[...]}',
			],
			'auth'      => 'Authorization: Bearer {api_key}',
			'docs'      => 'https://gunrack.deals/api/compliance',
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
	public function mykey(): void
	{
		$member = \IPS\Member::loggedIn();
		$h      = fn( string $s ) => htmlspecialchars( $s, ENT_QUOTES, 'UTF-8' );

		$selfUrl = (string) \IPS\Http\Url::internal(
			'app=gdcompliance&module=api&controller=api&do=mykey', 'front'
		);

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

		/* Active member → find or offer to create the key. */
		$key = null;
		try
		{
			$key = \IPS\Db::i()->select(
				'*', 'gd_compliance_api_keys',
				[ 'member_id=? AND status!=?', (int) $member->member_id, 'revoked' ],
				'id DESC', 1
			)->first();
		}
		catch ( \Throwable ) { $key = null; }

		$csrfKey = (string) \IPS\Session::i()->csrfKey;
		$html    = $this->mykeyStyles() . '<div class="gdak-wrap"><h1>Your Compliance API Key</h1>';

		if ( !is_array( $key ) )
		{
			$html .= '<div class="gdak-card">'
				. '<h2>Generate your API key</h2>'
				. '<p>You have an active Compliance API subscription. Generate a key below to start making requests.</p>'
				. '<form method="post" action="' . $h( $selfUrl ) . '">'
				. '<input type="hidden" name="csrfKey" value="' . $h( $csrfKey ) . '">'
				. '<input type="hidden" name="action" value="generate">'
				. '<button type="submit" class="gdak-btn">Generate API key</button>'
				. '</form>'
				. '</div>';
		}
		else
		{
			$keyStr = (string) ( $key['api_key']      ?? '' );
			$rc     = (int)    ( $key['request_count'] ?? 0 );
			$lu     = (int)    ( $key['last_used_at']  ?? 0 );
			$luStr  = $lu > 0 ? date( 'Y-m-d H:i', $lu ) . ' UTC' : 'never';
			$ca     = (int)    ( $key['created_at']   ?? 0 );
			$caStr  = $ca > 0 ? date( 'Y-m-d', $ca )  : '—';

			$html .= '<div class="gdak-card">'
				. '<h2>Your API key</h2>'
				. '<code class="gdak-key">' . $h( $keyStr ) . '</code>'
				. '<dl class="gdak-meta">'
				. '<dt>Created</dt><dd>' . $h( $caStr ) . '</dd>'
				. '<dt>Last used</dt><dd>' . $h( $luStr ) . '</dd>'
				. '<dt>Lifetime</dt><dd>' . number_format( $rc ) . ' requests</dd>'
				. '</dl>'
				. '<form method="post" action="' . $h( $selfUrl ) . '" onsubmit="return confirm(\'Regenerating will invalidate the existing key immediately. Any live integrations using it will break until you paste in the new key. Continue?\');" style="margin-top:12px">'
				. '<input type="hidden" name="csrfKey" value="' . $h( $csrfKey ) . '">'
				. '<input type="hidden" name="action" value="regenerate">'
				. '<button type="submit" class="gdak-btn gdak-btn--warn">Regenerate key</button>'
				. '</form>'
				. '</div>';

			/* v1.6.31 — tier / monthly usage panel. */
			$quota  = $this->computeQuota( $key );
			$used   = $this->readUsage( (int) $key['id'], $this->currentPeriod() );
			$reset  = $this->monthResetTs();
			$grpId  = $quota['group_id'] ?? null;
			$grpLbl = $grpId ? ( 'Group #' . (int) $grpId ) : ( $quota['is_unlimited'] ? 'Unlimited' : 'Default' );
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
				if ( $pct >= 75 )
				{
					$upsell = '<p class="gdak-hint">Approaching your monthly quota. <a href="' . $h( self::subscribeUrl() ) . '">Upgrade your subscription</a> to raise the cap.</p>';
				}
				elseif ( $pct >= 100 )
				{
					$upsell = '<p class="gdak-hint gdak-hint--danger">Monthly quota reached. Further requests will return 429 until ' . $h( $resetStr ) . '. <a href="' . $h( self::subscribeUrl() ) . '">Upgrade now</a> to continue immediately.</p>';
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
			. '<h2>How to use it</h2>'
			. '<p>Send your key in the <code>Authorization</code> header (preferred) or as an <code>api_key</code> query param.</p>'
			. '<pre class="gdak-pre">curl -H "Authorization: Bearer YOUR_KEY" \\'
			. '&#10;  "' . $h( (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=api&controller=api&do=check&upc=011356670526&state=IL', 'front' ) ) . '"</pre>'
			. '<h3>Endpoints</h3>'
			. '<ul>'
			. '<li><code>GET /api/compliance/check?upc=UPC&state=XX</code> — single verdict</li>'
			. '<li><code>POST /api/compliance/batch</code> body <code>{"state":"XX","upcs":[…]}</code> — up to 200 UPCs. Counts as one quota unit per UPC.</li>'
			. '<li><code>GET /api/compliance</code> — usage manifest</li>'
			. '</ul>'
			. '<h3>Response envelope</h3>'
			. '<p>Every response includes a plain-language <code>disclaimer</code> and a <code>verification_status</code> flag. Current verification status on this install: <strong>' . $h( $verified ) . '</strong>.</p>'
			. ( $disclaimer !== '' ? '<blockquote class="gdak-disclaimer">' . $h( $disclaimer ) . '</blockquote>' : '' )
			. '</div>';

		$html .= '</div>';
		\IPS\Output::i()->output = $html;
	}

	/**
	 * Handle the POST from the mykey page (generate | regenerate).
	 * CSRF-checked; only lets the member touch their OWN row.
	 */
	protected function mykeyAct(): void
	{
		\IPS\Session::i()->csrfCheck();

		$member = \IPS\Member::loggedIn();
		if ( !$member->member_id )
		{
			\IPS\Output::i()->redirect( (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=api&controller=api&do=mykey', 'front' ) );
			return;
		}
		if ( self::memberApiStatus( $member ) !== 'active' )
		{
			\IPS\Output::i()->redirect( (string) \IPS\Http\Url::internal( 'app=gdcompliance&module=api&controller=api&do=mykey', 'front' ) );
			return;
		}

		$action = (string) ( \IPS\Request::i()->action ?? '' );

		try { $newKey = 'gdc_' . bin2hex( random_bytes( 20 ) ); }
		catch ( \Throwable ) { $newKey = ''; }
		if ( $newKey === '' )
		{
			\IPS\Output::i()->error( 'Could not generate a secure key.', '2GDMK/1', 500 );
			return;
		}

		if ( $action === 'regenerate' )
		{
			try
			{
				\IPS\Db::i()->update(
					'gd_compliance_api_keys',
					[ 'status' => 'revoked' ],
					[ 'member_id=? AND status!=?', (int) $member->member_id, 'revoked' ]
				);
			}
			catch ( \Throwable ) {}
		}

		try
		{
			\IPS\Db::i()->insert( 'gd_compliance_api_keys', [
				'api_key'       => $newKey,
				'member_id'     => (int) $member->member_id,
				'label'         => 'Self-service (' . substr( (string) $member->name, 0, 60 ) . ')',
				'status'        => 'active',
				'created_at'    => time(),
				'last_used_at'  => null,
				'request_count' => 0,
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'mykeyAct insert: ' . $e->getMessage(), 'gdcompliance' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'Could not store the new key.', '2GDMK/2', 500 );
			return;
		}

		\IPS\Output::i()->redirect(
			(string) \IPS\Http\Url::internal( 'app=gdcompliance&module=api&controller=api&do=mykey', 'front' )
		);
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
			. '.gdak-key{display:block;background:#0f172a;color:#fef3c7;padding:12px 14px;border-radius:8px;font-family:ui-monospace,menlo,monospace;font-size:.95em;word-break:break-all;margin-bottom:14px}'
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
