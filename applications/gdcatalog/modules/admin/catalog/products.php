<?php
/**
 * @brief       GD Master Catalog — ACP Products Controller
 * @package     IPS Community Suite
 * @subpackage  GD Master Catalog
 * @since       12 Apr 2026
 *
 * Product search/browse by UPC, title, brand, category.
 * Inline edit with field locking (Section 2.2.3).
 * Admin Review queue with one-click resolve.
 */

namespace IPS\gdcatalog\modules\admin\catalog;

/* To prevent PHP errors (extending class does not exist) revealing path */

use IPS\gdcatalog\Catalog\Product;
use IPS\gdcatalog\Catalog\Category;
use IPS\gdcatalog\Conflict\FieldLock;
use IPS\gdcatalog\Search\OpenSearchIndexer;
use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _products extends \IPS\Dispatcher\Controller
{
	public static bool $csrfProtected = TRUE;

	public function execute(): void
	{
		\IPS\Dispatcher::i()->checkAcpPermission( 'catalog_manage' );
		parent::execute();
	}

	/**
	 * v1.0.23: Recursively collect all descendant category IDs for a given
	 * parent. Walks the gd_categories tree, returning a flat array of IDs.
	 *
	 * Self-protected against infinite recursion via $visited tracking.
	 * Returns empty array if $parentId has no children (leaf node).
	 *
	 * @param  int    $parentId   The category ID to find descendants of.
	 * @param  array  $visited    Internal recursion guard.
	 * @return int[]
	 */
	protected static function collectCategoryDescendants( int $parentId, array $visited = [] ): array
	{
		if ( in_array( $parentId, $visited, true ) )
		{
			return [];
		}
		$visited[] = $parentId;

		$descendants = [];
		try
		{
			foreach ( \IPS\Db::i()->select( 'id', 'gd_categories', [ 'parent_id=?', $parentId ] ) as $childId )
			{
				$childId = (int) $childId;
				if ( $childId <= 0 || in_array( $childId, $visited, true ) )
				{
					continue;
				}
				$descendants[] = $childId;

				/* Recurse to capture grandchildren */
				$grandchildren = self::collectCategoryDescendants( $childId, $visited );
				foreach ( $grandchildren as $gc )
				{
					$descendants[] = $gc;
				}
			}
		}
		catch ( \Throwable )
		{
			/* If query fails, return what we have so far - the calling code
			 * will fall back to exact-match behavior for the parent. */
		}

		return $descendants;
	}

	/**
	 * Product list with search/filter.
	 *
	 * Products and categories are flattened into scalar arrays and
	 * all dynamic URLs (edit, approve, form action) are pre-built in
	 * the controller. The template then only uses plain `{$row['key']}`
	 * access per Rule #12 — no nested `{url="...{$x->upc}"}` tokens.
	 */
	protected function manage()
	{
		$where        = [];
		$search       = \IPS\Request::i()->q ?? '';
		$status       = \IPS\Request::i()->status ?? '';
		$catId        = (int) ( \IPS\Request::i()->category ?? 0 );
		$missingField = (string) ( \IPS\Request::i()->missing_field ?? '' );

		if ( $search !== '' )
		{
			$where[] = [
				'(upc LIKE ? OR title LIKE ? OR brand LIKE ?)',
				'%' . $search . '%', '%' . $search . '%', '%' . $search . '%'
			];
		}

		if ( $status !== '' )
		{
			$where[] = [ 'record_status=?', $status ];
		}

		/* v1.0.28: Image status filter - expanded for HEAD-validation states.
		 *
		 * Values:
		 *   missing   - image_url is NULL or empty string
		 *   present   - image_url has a value (regardless of validation)
		 *   unchecked - has URL but never validated yet (image_validated IS NULL)
		 *   ok        - validated and status 200-399
		 *   broken    - validated and status >= 400 or = 0 (network errors)
		 */
		$imageStatus = (string) ( \IPS\Request::i()->image_status ?? '' );
		$validImageStatuses = [ 'missing', 'present', 'unchecked', 'ok', 'broken' ];
		if ( !in_array( $imageStatus, $validImageStatuses, true ) )
		{
			$imageStatus = '';
		}

		if ( $imageStatus === 'missing' )
		{
			$where[] = [ 'image_url IS NULL OR image_url = ?', '' ];
		}
		elseif ( $imageStatus === 'present' )
		{
			$where[] = [ 'image_url IS NOT NULL AND image_url != ?', '' ];
		}
		elseif ( $imageStatus === 'unchecked' )
		{
			$where[] = [ 'image_url IS NOT NULL AND image_url != ? AND image_validated IS NULL', '' ];
		}
		elseif ( $imageStatus === 'ok' )
		{
			$where[] = [ 'image_validated = 1 AND image_http_status >= 200 AND image_http_status < 400' ];
		}
		elseif ( $imageStatus === 'broken' )
		{
			$where[] = [ 'image_validated = 1 AND (image_http_status >= 400 OR image_http_status = 0)' ];
		}

		/* v1.0.23: Parent-aware category filter.
		 *
		 * gd_categories is hierarchical (Ammunition parent has children
		 * Handgun Ammo, Rifle Ammo, Shotgun Ammo, etc). When admin selects
		 * a parent category in the filter dropdown, they expect to see ALL
		 * products in that parent OR any of its descendants.
		 *
		 * For example: selecting "Ammunition" (id=17) should match
		 * category_id IN (17, 18, 19, 20, 21, 22) since those subcategories
		 * have parent_id=17.
		 *
		 * Strategy: collect the selected category's ID plus all its
		 * descendant IDs (recursively, in case of multi-level hierarchy),
		 * then filter with IN clause. */
		if ( $catId > 0 )
		{
			$descendantIds = self::collectCategoryDescendants( $catId );
			$descendantIds[] = $catId;
			$descendantIds = array_unique( $descendantIds );

			if ( count( $descendantIds ) === 1 )
			{
				/* Leaf category - exact match */
				$where[] = [ 'category_id=?', $catId ];
			}
			else
			{
				/* Parent with children - IN clause.
				 * Build placeholders dynamically since IPS\Db needs them. */
				$placeholders = implode( ',', array_fill( 0, count( $descendantIds ), '?' ) );
				$where[] = array_merge(
					[ 'category_id IN (' . $placeholders . ')' ],
					$descendantIds
				);
			}
		}

		$validMissingFields = [
			'caliber', 'capacity', 'action_type', 'barrel_length', 'weight_lbs',
			'overall_length', 'gun_type', 'safety_type', 'sight_type', 'stock_material',
			'trigger_type', 'grips', 'gauge', 'bullet_type', 'rounds_per_box',
			'magnification', 'metal_finish', 'frame_finish', 'image_url', 'brand', 'mpn',
		];

		if ( $missingField !== '' && in_array( $missingField, $validMissingFields, true ) )
		{
			$relevantCats = [];
			foreach ( \IPS\gdcatalog\Feed\CompletenessScorer::REQUIRED_FIELDS as $cId => $fields )
			{
				if ( in_array( $missingField, $fields, true ) )
				{
					$relevantCats[] = $cId;
				}
			}

			if ( $missingField === 'image_url' )
			{
				$where[] = [ 'image_url IS NULL OR image_url=?', '' ];
			}
			elseif ( !empty( $relevantCats ) )
			{
				$catPlaceholders = implode( ',', array_fill( 0, count( $relevantCats ), '?' ) );
				$where[] = array_merge(
					[ '( ' . $missingField . ' IS NULL OR ' . $missingField . '=? ) AND category_id IN (' . $catPlaceholders . ')', '' ],
					$relevantCats
				);
			}
			else
			{
				$where[] = [ $missingField . ' IS NULL OR ' . $missingField . '=?', '' ];
			}
		}

		$page    = max( 1, (int) ( \IPS\Request::i()->page ?? 1 ) );
		$perPage = 50;

		$total = (int) \IPS\Db::i()->select( 'COUNT(*)', 'gd_catalog', $where )->first();

		$products = [];
		foreach (
			\IPS\Db::i()->select( '*', 'gd_catalog', $where, 'last_updated DESC', [ ( $page - 1 ) * $perPage, $perPage ] ) as $row
		)
		{
			$product = Product::constructFromData( $row );

			$editUrl = (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( (string) $product->upc )
			);
			$approveUrl = (string) \IPS\Http\Url::internal(
				'app=gdcatalog&module=catalog&controller=products&do=resolveReview&upc=' . urlencode( (string) $product->upc )
			)->csrf();

			/* v1.0.34: Count of locked fields for the 🔒 column. Reads from
			 * Product->locked_fields JSON column (the actively-used source of
			 * truth). Try/catch wrapped because getLockedFields() decodes JSON
			 * and could fail on legacy/corrupt data. */
			$lockedFieldsCount = 0;
			try
			{
				$lockedFieldsCount = count( $product->getLockedFields() );
			}
			catch ( \Throwable ) {}

			$products[] = [
				'upc'                => (string) $product->upc,
				'title'              => (string) ( $product->title ?? '' ),
				'brand'              => (string) ( $product->brand ?? '' ),
				'caliber'            => (string) ( $product->caliber ?? '' ),
				'msrp'               => $product->msrp !== null ? '$' . number_format( (float) $product->msrp, 2 ) : '—',
				'record_status'      => (string) ( $product->record_status ?? '' ),
				'primary_source'     => (string) ( $product->primary_source ?? '' ),
				'edit_url'           => $editUrl,
				'approve_url'        => $approveUrl,

				/* v1.0.28: Image validation state for the image badge column */
				'image_url'          => (string) ( $product->image_url ?? '' ),
				'image_validated'    => $product->image_validated !== null ? (int) $product->image_validated : null,
				'image_http_status'  => $product->image_http_status !== null ? (int) $product->image_http_status : null,

				/* v1.0.34: Locked fields count for the Lock column. */
				'locked_fields_count' => $lockedFieldsCount,

				'completeness_score' => (int) ( $product->completeness_score ?? 0 ),
				'missing_fields' => ( function() use ( $product ) {
					$pCatId = (int) ( $product->category_id ?? 0 );
					$requiredFields = \IPS\gdcatalog\Feed\CompletenessScorer::REQUIRED_FIELDS[ $pCatId ] ?? [];
					if ( empty( $requiredFields ) )
					{
						try
						{
							$parentId = (int) \IPS\Db::i()->select( 'parent_id', 'gd_categories', [ 'id=?', $pCatId ] )->first();
							$requiredFields = \IPS\gdcatalog\Feed\CompletenessScorer::REQUIRED_FIELDS[ $parentId ] ?? [];
						}
						catch ( \Throwable ) {}
					}
					$baseFields = [ 'title', 'brand', 'msrp', 'image_url' ];
					$allRequired = array_unique( array_merge( $baseFields, $requiredFields ) );
					$missing = [];
					foreach ( $allRequired as $f )
					{
						$val = $product->$f ?? null;
						if ( $val === null || $val === '' ) { $missing[] = $f; }
					}
					return $missing;
				} )(),
			];
		}

		$categories = [];
		foreach ( Category::roots() as $cat )
		{
			$categories[] = [
				'id'   => (int) $cat->id,
				'name' => (string) $cat->name,
			];
		}

		$formActionUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=products'
		);

		$pagination = \IPS\Theme::i()->getTemplate( 'global', 'core', 'global' )->pagination(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products' ),
			(int) ceil( $total / $perPage ),
			$page,
			$perPage
		);

		$productCount  = \count( $products );
		$categoryCount = \count( $categories );

		/* v1.0.31: Build the Add Product URL for the new manual-entry button. */
		$addProductUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=products&do=add'
		);

		/* v1.0.38: CSV bulk-import + template download URLs. */
		$importCsvUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=products&do=importCsv'
		);

		$downloadCsvTemplateUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=products&do=downloadCsvTemplate'
		);

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_products_title' );
		\IPS\Output::i()->output = \IPS\Theme::i()->getTemplate( 'catalog', 'gdcatalog', 'admin' )->productList(
			$products, $categories, $search, $status, $catId, $imageStatus,
			$total, $pagination, $formActionUrl, $productCount, $categoryCount,
			$addProductUrl, $importCsvUrl, $downloadCsvTemplateUrl, $missingField
		);
	}

	/**
	 * v1.0.31: Manually create a new product.
	 *
	 * Renders a blank form with editable UPC. On save, inserts a new
	 * gd_catalog row with primary_source='manual' and auto-locks every
	 * populated field via Product::lockField(). Locked fields prevent
	 * distributor imports from silently overwriting admin-entered data -
	 * any incoming distributor change routes to gd_feed_conflicts for
	 * admin review (or 48hr auto-resolve).
	 */
	protected function add()
	{
		$form = new \IPS\Helpers\Form( 'manual_product', 'gdcatalog_save_manual' );

		/* UPC is editable + required for new products. */
		$form->add( new \IPS\Helpers\Form\Text(
			'gdcatalog_product_upc',
			'',
			TRUE,
			[ 'maxLength' => 20 ]
		));

		/* Same editable fields as edit() - kept in sync intentionally. */
		$editableFields = [
			/* Identity */
			'title'          => [ 'Text',     255 ],
			'mpn'            => [ 'Text',     100 ],
			'brand'          => [ 'Text',     100 ],
			'manufacturer'   => [ 'Text',     100 ],
			'importer'       => [ 'Text',     100 ],
			'model'          => [ 'Text',     100 ],

			/* Classification */
			'gun_type'       => [ 'Text',     100 ],
			'action_type'    => [ 'Text',     100 ],
			'safety_type'    => [ 'Text',     100 ],
			'trigger_type'   => [ 'Text',     255 ],

			/* Firearm specs */
			'caliber'        => [ 'Text',     100 ],
			'capacity'       => [ 'Text',     50  ],
			'barrel_length'  => [ 'Text',     50  ],
			'overall_length' => [ 'Text',     50  ],
			'weight_lbs'     => [ 'Text',     50  ],
			'gauge'          => [ 'Text',     50  ],

			/* Appearance */
			'finish'         => [ 'Text',     100 ],
			'metal_finish'   => [ 'Text',     255 ],
			'frame_finish'   => [ 'Text',     255 ],
			'stock_material' => [ 'Text',     100 ],
			'stock_type'     => [ 'Text',     100 ],
			'sight_type'     => [ 'Text',     100 ],
			'grips'          => [ 'Text',     255 ],
			'hammer_style'   => [ 'Text',     100 ],
			'choke_config'   => [ 'Text',     100 ],
			'chamber'        => [ 'Text',     100 ],
			'receiver_desc'  => [ 'Text',     255 ],
			'receiver_type'  => [ 'Text',     255 ],
			'frame_material' => [ 'Text',     100 ],
			'slide_material' => [ 'Text',     100 ],

			/* Ammunition */
			'bullet_type'      => [ 'Text',   100 ],
			'bullet_weight'    => [ 'Text',   50  ],
			'muzzle_velocity'  => [ 'Text',   50  ],
			'muzzle_energy'    => [ 'Text',   50  ],
			'rounds_per_box'   => [ 'Text',   50  ],
			'boxes_per_case'   => [ 'Text',   50  ],
			'casing_material'  => [ 'Text',   100 ],

			/* Optics */
			'magnification'  => [ 'Text',     100 ],
			'objective_mm'   => [ 'Text',     50  ],
			'reticle'        => [ 'Text',     100 ],
			'tube_diameter'  => [ 'Text',     50  ],
			'eye_relief'     => [ 'Text',     100 ],

			/* General */
			'features'       => [ 'TextArea', null ],
			'image_url'      => [ 'Text',     500 ],
			'msrp'           => [ 'Number',   null ],
			'description'    => [ 'TextArea', null ],
		];

		foreach ( $editableFields as $field => $config )
		{
			$formClass = $config[0] === 'TextArea'
				? \IPS\Helpers\Form\TextArea::class
				: ( $config[0] === 'Number' ? \IPS\Helpers\Form\Number::class : \IPS\Helpers\Form\Text::class );

			$form->add( new $formClass(
				'gdcatalog_product_' . $field,
				'',
				FALSE,
				$config[0] === 'Number' ? [ 'decimals' => 2 ] : []
			));
		}

		/* Boolean flags */
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcatalog_product_nfa_item',     0 ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcatalog_product_requires_ffl', 0 ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcatalog_product_is_ammo',      0 ) );

		/* Status - default active for manual entries. */
		$form->add( new \IPS\Helpers\Form\Select(
			'gdcatalog_product_status', 'active', TRUE,
			[ 'options' => [
				'active'       => 'Active',
				'discontinued' => 'Discontinued',
				'admin_review' => 'Admin Review',
				'pending'      => 'Pending',
			]]
		));

		if ( $values = $form->values() )
		{
			\IPS\Session::i()->csrfCheck();

			$upc = trim( (string) $values['gdcatalog_product_upc'] );

			if ( $upc === '' )
			{
				\IPS\Output::i()->error( 'UPC is required.', '2GDC102/A1', 400 );
				return;
			}

			/* Reject duplicates - if a row already exists, redirect to edit. */
			try
			{
				$existing = Product::load( $upc );
				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( $upc ) ),
					'A product with that UPC already exists - opened for editing'
				);
				return;
			}
			catch ( \OutOfRangeException )
			{
				/* Good - UPC is free to use */
			}

			/* Insert the new product. */
			$product = new Product;
			$product->upc = $upc;

			$populatedFields = [];

			foreach ( $editableFields as $field => $config )
			{
				$value = $values[ 'gdcatalog_product_' . $field ];
				if ( $value !== null && $value !== '' )
				{
					$product->$field = $value;
					$populatedFields[] = $field;
				}
			}

			$product->nfa_item      = (int) $values['gdcatalog_product_nfa_item'];
			$product->requires_ffl  = (int) $values['gdcatalog_product_requires_ffl'];
			$product->is_ammo       = (int) $values['gdcatalog_product_is_ammo'];
			$product->record_status = $values['gdcatalog_product_status'];
			$product->primary_source = 'manual';
			$product->distributor_sources = 'manual';
			$product->last_updated  = date( 'Y-m-d H:i:s' );

			/* v1.0.31: Auto-lock every populated field. The existing
			 * ConflictResolver in Importer.php checks isFieldLocked() and
			 * routes any incoming distributor change to gd_feed_conflicts. */
			foreach ( $populatedFields as $field )
			{
				$product->lockField( $field );
			}

			$product->save();

			/* Reindex */
			try { OpenSearchIndexer::i()->indexProduct( $product ); } catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( $upc ) ),
				sprintf( 'Product created with %d locked fields', count( $populatedFields ) )
			);
		}

		\IPS\Output::i()->title  = 'Add Product';
		\IPS\Output::i()->output = (string) $form;
	}

	/**
	 * v1.0.38: Stream a blank CSV template with canonical column names.
	 *
	 * Admin downloads this file, fills it out, then uploads via importCsv().
	 * Headers match the canonical fields admin can populate (subset of the
	 * gd_catalog columns from v1.0.31 add() editableFields).
	 */
	protected function downloadCsvTemplate(): void
	{
		$canonicalFields = $this->getCanonicalCsvFields();

		$tmpFile = tempnam( sys_get_temp_dir(), 'gdcat_csv_' );
		$out = fopen( $tmpFile, 'w' );
		fputcsv( $out, $canonicalFields );
		fputcsv( $out, [
			'012345678901', 'Example Rifle 22LR', 'EX-123', 'ExampleBrand', '', '',
			'ExampleModel-22', 'rifle', '22 LR', 'bolt', '10', '20',
			'38', '96', 'blue', 'manual',
			'synthetic', 'iron', 'aluminum', 'aluminum',
			'https://example.com/image.jpg', '499.99', 'Example description',
			'0', '1', '0',
		] );
		fclose( $out );

		$csvContent = file_get_contents( $tmpFile );
		unlink( $tmpFile );

		\IPS\Output::i()->sendOutput(
			$csvContent,
			200,
			'text/csv',
			[
				'Content-Disposition' => 'attachment; filename="gdcatalog_import_template.csv"',
				'Cache-Control'       => 'no-store, no-cache, must-revalidate',
				'Pragma'              => 'no-cache',
			],
			FALSE,
			FALSE,
			FALSE
		);
	}

	/**
	 * v1.0.38: Render the CSV import form. Two modes:
	 *   - Simple: CSV has canonical column names, auto-mapped
	 *   - Advanced: after upload, admin maps each CSV column manually
	 *
	 * On submit:
	 *   - Simple: save file to temp, build auto-mapping from row 1 headers,
	 *     enqueue CsvBulkImport queue directly
	 *   - Advanced: save file to temp, store path + headers in core_store
	 *     keyed by member_id, redirect to importCsvMap
	 */
	protected function importCsv()
	{
		$form = new \IPS\Helpers\Form( 'csv_import', 'gdcatalog_csv_submit' );

		$form->add( new \IPS\Helpers\Form\Radio(
			'gdcatalog_csv_mode',
			'simple',
			TRUE,
			[
				'options' => [
					'simple'   => 'gdcatalog_csv_mode_simple',
					'advanced' => 'gdcatalog_csv_mode_advanced',
				],
			]
		));

		$form->add( new \IPS\Helpers\Form\Upload(
			'gdcatalog_csv_upload',
			null,
			TRUE,
			[
				'temporary'          => TRUE,
				'allowedFileTypes'   => [ 'csv', 'txt' ],
				'storageExtension'   => 'core_Attachment',
				'maxFileSize'        => 50, /* MB */
			]
		));

		if ( $values = $form->values() )
		{
			\IPS\Session::i()->csrfCheck();

			$mode = (string) $values['gdcatalog_csv_mode'];

			/* Persist the uploaded file to a temp path we control. The
			 * Form\Upload "temporary" file may get cleaned up; we copy it. */
			$uploadedFile = $values['gdcatalog_csv_upload'];

			$tmpDir = \IPS\TEMP_DIRECTORY ?: sys_get_temp_dir();
			$tmpPath = $tmpDir . '/gdcatalog_csv_' . bin2hex( random_bytes( 8 ) ) . '.csv';

			try
			{
				$contents = $uploadedFile->contents();
				file_put_contents( $tmpPath, $contents );
			}
			catch ( \Throwable $e )
			{
				\IPS\Output::i()->error( 'Failed to read uploaded file: ' . $e->getMessage(), '2GDC103/I1', 500 );
				return;
			}

			/* Read header row + count data rows for progress UI. */
			$handle = @fopen( $tmpPath, 'r' );
			if ( $handle === false )
			{
				@unlink( $tmpPath );
				\IPS\Output::i()->error( 'Failed to read CSV file', '2GDC103/I2', 500 );
				return;
			}

			$header = fgetcsv( $handle );
			if ( $header === false || empty( $header ) )
			{
				fclose( $handle );
				@unlink( $tmpPath );
				\IPS\Output::i()->error( 'CSV file is empty or has no header row', '2GDC103/I3', 400 );
				return;
			}

			$dataRowCount = 0;
			while ( ( $row = fgetcsv( $handle ) ) !== false )
			{
				if ( count( array_filter( $row, fn( $v ) => trim( (string) $v ) !== '' ) ) > 0 )
				{
					$dataRowCount++;
				}
			}
			fclose( $handle );

			if ( $dataRowCount === 0 )
			{
				@unlink( $tmpPath );
				\IPS\Output::i()->error( 'CSV file has no data rows', '2GDC103/I4', 400 );
				return;
			}

			/* Resolve manual_csv feed id. */
			try
			{
				$feedId = (int) \IPS\Db::i()->select(
					'id', 'gd_distributor_feeds',
					[ 'distributor=?', 'manual_csv' ]
				)->first();
			}
			catch ( \Throwable )
			{
				@unlink( $tmpPath );
				\IPS\Output::i()->error( 'Manual CSV feed not configured. Re-install v1.0.38 upgrade.', '2GDC103/I5', 500 );
				return;
			}

			$canonicalFields = $this->getCanonicalCsvFields();

			if ( $mode === 'simple' )
			{
				/* Build auto-mapping from canonical names in header row. */
				$mapping = [];
				foreach ( $header as $colName )
				{
					$normalized = strtolower( trim( (string) $colName ) );
					if ( in_array( $normalized, $canonicalFields, true ) )
					{
						$mapping[ $normalized ] = $normalized;
					}
				}

				if ( !isset( $mapping['upc'] ) )
				{
					@unlink( $tmpPath );
					\IPS\Output::i()->error( 'CSV must include a "upc" column in Simple mode. Use Advanced mode to map a differently-named column.', '2GDC103/I6', 400 );
					return;
				}

				$this->enqueueCsvImport( $tmpPath, $mapping, $dataRowCount, $feedId );
				return;
			}

			/* Advanced mode - stash file path + headers, redirect to mapping page. */
			$memberId = (int) \IPS\Member::loggedIn()->member_id;
			$key      = 'gdcatalog_csv_pending_' . $memberId;

			\IPS\Data\Store::i()->$key = [
				'file_path'  => $tmpPath,
				'headers'    => array_map( fn( $h ) => trim( (string) $h ), $header ),
				'data_rows'  => $dataRowCount,
				'feed_id'    => $feedId,
				'created_at' => time(),
			];

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products&do=importCsvMap' )
			);
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_csv_import_title' );
		\IPS\Output::i()->output = (string) $form;
	}

	/**
	 * v1.0.38: Advanced-mode column mapping page.
	 *
	 * After Advanced upload, file is stashed in core_store. This renders a
	 * form with one dropdown per CSV column, with options = canonical fields
	 * + "(ignore)". On submit, builds the mapping array and enqueues.
	 */
	protected function importCsvMap()
	{
		$memberId = (int) \IPS\Member::loggedIn()->member_id;
		$key      = 'gdcatalog_csv_pending_' . $memberId;

		$pending = \IPS\Data\Store::i()->$key ?? null;
		if ( !is_array( $pending ) || empty( $pending['file_path'] ) || !is_file( $pending['file_path'] ) )
		{
			\IPS\Output::i()->error( 'No pending CSV upload found. Please re-upload.', '2GDC103/M1', 404 );
			return;
		}

		$canonicalFields = $this->getCanonicalCsvFields();

		$fieldOptions = [ '__ignore__' => '(ignore this column)' ];
		foreach ( $canonicalFields as $cf )
		{
			$fieldOptions[ $cf ] = $cf;
		}

		$form = new \IPS\Helpers\Form( 'csv_map', 'gdcatalog_csv_map_submit' );

		foreach ( $pending['headers'] as $idx => $header )
		{
			$normalized = strtolower( trim( (string) $header ) );
			$default    = in_array( $normalized, $canonicalFields, true ) ? $normalized : '__ignore__';

			$form->add( new \IPS\Helpers\Form\Select(
				'csvcol_' . $idx,
				$default,
				FALSE,
				[ 'options' => $fieldOptions ],
				NULL,
				NULL,
				NULL,
				'csvcol_' . $idx
			));

			\IPS\Member::loggedIn()->language()->words[ 'csvcol_' . $idx ] = 'Column: ' . $header;
		}

		if ( $values = $form->values() )
		{
			\IPS\Session::i()->csrfCheck();

			$mapping = [];
			foreach ( $pending['headers'] as $idx => $header )
			{
				$picked = (string) ( $values[ 'csvcol_' . $idx ] ?? '__ignore__' );

				if ( $picked === '__ignore__' )
				{
					continue;
				}

				$normalized = strtolower( trim( (string) $header ) );
				$mapping[ $normalized ] = $picked;
			}

			if ( !in_array( 'upc', $mapping, true ) )
			{
				\IPS\Output::i()->error( 'You must map exactly one column to "upc" - it is required.', '2GDC103/M2', 400 );
				return;
			}

			$this->enqueueCsvImport( $pending['file_path'], $mapping, (int) $pending['data_rows'], (int) $pending['feed_id'] );

			unset( \IPS\Data\Store::i()->$key );
			return;
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_csv_map_title' );
		\IPS\Output::i()->output = '<p style="margin-bottom:16px">' . \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_csv_map_desc' ) . '</p>' . (string) $form;
	}

	/**
	 * v1.0.38: Helper - enqueue CsvBulkImport queue job and redirect.
	 */
	protected function enqueueCsvImport( string $filePath, array $mapping, int $totalRows, int $feedId ): void
	{
		try
		{
			\IPS\Task::queue(
				'gdcatalog',
				'CsvBulkImport',
				[
					'file_path'  => $filePath,
					'mapping'    => $mapping,
					'total_rows' => $totalRows,
					'feed_id'    => $feedId,
					'member_id'  => (int) \IPS\Member::loggedIn()->member_id,
				],
				4,
				[ 'file_path' ]
			);

			try { \IPS\Log::log( sprintf( 'Admin enqueued CsvBulkImport: rows=%d feed_id=%d mapping=%s', $totalRows, $feedId, json_encode( $mapping ) ), 'gdcatalog_csv' ); } catch ( \Throwable ) {}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products' ),
				sprintf( 'CSV import queued (%d rows). Progress visible in AdminCP -> System -> Background Processes.', $totalRows )
			);
		}
		catch ( \Throwable $e )
		{
			@unlink( $filePath );
			try { \IPS\Log::log( 'CsvBulkImport enqueue failed: ' . $e->getMessage(), 'gdcatalog_csv' ); } catch ( \Throwable ) {}
			\IPS\Output::i()->error( 'Failed to enqueue CSV import: ' . $e->getMessage(), '2GDC103/E1', 500 );
		}
	}

	/**
	 * v1.0.38: Helper - canonical field names for CSV upload.
	 * Kept in sync with v1.0.31 add() editableFields plus boolean flags.
	 */
	protected function getCanonicalCsvFields(): array
	{
		return [
			'upc',
			'title', 'mpn', 'brand', 'manufacturer', 'importer', 'model',
			'gun_type', 'caliber', 'action_type', 'capacity', 'barrel_length',
			'overall_length', 'weight_oz', 'finish', 'safety_type',
			'stock_type', 'sight_type', 'receiver_type', 'frame_material',
			'image_url', 'msrp', 'description',
			'nfa_item', 'requires_ffl', 'is_ammo',
		];
	}

	protected function editProduct(): void
	{
		if ( \IPS\Request::i()->requestMethod() === 'POST' )
		{
			\IPS\Session::i()->csrfCheck();
		}

		$upc = (string) \IPS\Request::i()->upc;
		try
		{
			$product = \IPS\Db::i()->select( '*', 'gd_catalog', [ 'upc=?', $upc ] )->first();
		}
		catch ( \UnderflowException )
		{
			\IPS\Output::i()->error( 'gdcatalog_product_not_found', '2GDC/1', 404 );
			return;
		}

		$form = new \IPS\Helpers\Form;
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_edit_upc', $product['upc'], TRUE, [ 'regex' => '/^[0-9]{8,13}$/' ] ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_edit_title', $product['title'], TRUE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_edit_brand', $product['brand'], FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_edit_caliber', $product['caliber'] ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_edit_model', $product['model'] ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_edit_mpn', $product['mpn'] ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Number( 'gdcatalog_edit_msrp', $product['msrp'] ?? 0, FALSE, [ 'decimals' => 2 ] ) );
		$form->add( new \IPS\Helpers\Form\Select( 'gdcatalog_edit_status', $product['record_status'], TRUE, [
			'options' => [
				'active'       => 'Active',
				'discontinued' => 'Discontinued',
				'admin_review' => 'Admin Review',
				'pending'      => 'Pending',
			]
		] ) );
		$form->add( new \IPS\Helpers\Form\TextArea( 'gdcatalog_edit_description', $product['description'] ?? '', FALSE ) );
		$form->add( new \IPS\Helpers\Form\Text( 'gdcatalog_edit_image_url', $product['image_url'] ?? '', FALSE ) );

		if ( $values = $form->values() )
		{
			$newUpc = preg_replace( '/[^0-9]/', '', $values['gdcatalog_edit_upc'] );

			if ( $newUpc !== $upc )
			{
				$newData = $product;
				$newData['upc']           = $newUpc;
				$newData['title']         = $values['gdcatalog_edit_title'];
				$newData['brand']         = $values['gdcatalog_edit_brand'];
				$newData['caliber']       = $values['gdcatalog_edit_caliber'] ?: null;
				$newData['model']         = $values['gdcatalog_edit_model'] ?: null;
				$newData['mpn']           = $values['gdcatalog_edit_mpn'] ?: null;
				$newData['msrp']          = $values['gdcatalog_edit_msrp'];
				$newData['record_status'] = $values['gdcatalog_edit_status'];
				$newData['description']   = $values['gdcatalog_edit_description'] ?: null;
				$newData['image_url']     = $values['gdcatalog_edit_image_url'] ?: null;

				try
				{
					\IPS\Db::i()->delete( 'gd_catalog', [ 'upc=?', $upc ] );
					\IPS\Db::i()->insert( 'gd_catalog', $newData );
				}
				catch ( \Throwable $e )
				{
					\IPS\Log::log( $e, 'gdcatalog_upc_edit' );
					\IPS\Output::i()->error( 'gdcatalog_upc_edit_failed', '2GDC/2', 500 );
					return;
				}
			}
			else
			{
				\IPS\Db::i()->update( 'gd_catalog', [
					'title'         => $values['gdcatalog_edit_title'],
					'brand'         => $values['gdcatalog_edit_brand'],
					'caliber'       => $values['gdcatalog_edit_caliber'] ?: null,
					'model'         => $values['gdcatalog_edit_model'] ?: null,
					'mpn'           => $values['gdcatalog_edit_mpn'] ?: null,
					'msrp'          => $values['gdcatalog_edit_msrp'],
					'record_status' => $values['gdcatalog_edit_status'],
					'description'   => $values['gdcatalog_edit_description'] ?: null,
					'image_url'     => $values['gdcatalog_edit_image_url'] ?: null,
				], [ 'upc=?', $upc ] );
			}

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products' ),
				'gdcatalog_product_saved'
			);
		}

		\IPS\Output::i()->title  = \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_edit_product_title' );
		\IPS\Output::i()->output = (string) $form;
	}

	/**
	 * Edit a single product (legacy form).
	 */
	protected function edit()
	{
		$upc = \IPS\Request::i()->upc;

		try
		{
			$product = Product::load( $upc );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->error( 'node_error', '2GDC102/1', 404 );
			return;
		}

		/* v1.0.32: Read locks from Product->locked_fields JSON column (where
		 * lockField()/unlockField() actually write) instead of the
		 * gd_field_locks table (which is empty - it was scaffolded for a
		 * different lock model that never got wired up). */
		$lockedFieldNames = $product->getLockedFields();

		$form = new \IPS\Helpers\Form;

		$form->add( new \IPS\Helpers\Form\Text(
			'gdcatalog_product_upc',
			$product->upc,
			TRUE,
			[ 'regex' => '/^[0-9]{8,13}$/' ]
		) );

		/* Editable fields - v1.0.74 expanded with all attribute columns. */
		$editableFields = [
			/* Identity */
			'title'          => [ 'Text',     255 ],
			'mpn'            => [ 'Text',     100 ],
			'brand'          => [ 'Text',     100 ],
			'manufacturer'   => [ 'Text',     100 ],
			'importer'       => [ 'Text',     100 ],
			'model'          => [ 'Text',     100 ],

			/* Classification */
			'gun_type'       => [ 'Text',     100 ],
			'action_type'    => [ 'Text',     100 ],
			'safety_type'    => [ 'Text',     100 ],
			'trigger_type'   => [ 'Text',     255 ],

			/* Firearm specs */
			'caliber'        => [ 'Text',     100 ],
			'capacity'       => [ 'Text',     50  ],
			'barrel_length'  => [ 'Text',     50  ],
			'overall_length' => [ 'Text',     50  ],
			'weight_lbs'     => [ 'Text',     50  ],
			'gauge'          => [ 'Text',     50  ],

			/* Appearance */
			'finish'         => [ 'Text',     100 ],
			'metal_finish'   => [ 'Text',     255 ],
			'frame_finish'   => [ 'Text',     255 ],
			'stock_material' => [ 'Text',     100 ],
			'stock_type'     => [ 'Text',     100 ],
			'sight_type'     => [ 'Text',     100 ],
			'grips'          => [ 'Text',     255 ],
			'hammer_style'   => [ 'Text',     100 ],
			'choke_config'   => [ 'Text',     100 ],
			'chamber'        => [ 'Text',     100 ],
			'receiver_desc'  => [ 'Text',     255 ],
			'receiver_type'  => [ 'Text',     255 ],
			'frame_material' => [ 'Text',     100 ],
			'slide_material' => [ 'Text',     100 ],

			/* Ammunition */
			'bullet_type'      => [ 'Text',   100 ],
			'bullet_weight'    => [ 'Text',   50  ],
			'muzzle_velocity'  => [ 'Text',   50  ],
			'muzzle_energy'    => [ 'Text',   50  ],
			'rounds_per_box'   => [ 'Text',   50  ],
			'boxes_per_case'   => [ 'Text',   50  ],
			'casing_material'  => [ 'Text',   100 ],

			/* Optics */
			'magnification'  => [ 'Text',     100 ],
			'objective_mm'   => [ 'Text',     50  ],
			'reticle'        => [ 'Text',     100 ],
			'tube_diameter'  => [ 'Text',     50  ],
			'eye_relief'     => [ 'Text',     100 ],

			/* General */
			'features'       => [ 'TextArea', null ],
			'image_url'      => [ 'Text',     500 ],
			'msrp'           => [ 'Number',   null ],
			'description'    => [ 'TextArea', null ],
		];

		$catId = (int) $product->category_id;
		$firearmCats   = [1,2,3,4,5,7,8,9,10,11,12,13,14,15,31,64,96];
		$shotgunCats   = [16,17,18,19,20,21,22];
		$ammoCats      = [23,24,25,26,27,28,29,30];
		$opticCats     = [44,45,46,47,48,49,50,51];
		$magazineCats  = [38];

		if ( in_array( $catId, $firearmCats, true ) )       $defaultType = 'firearm';
		elseif ( in_array( $catId, $shotgunCats, true ) )   $defaultType = 'shotgun';
		elseif ( in_array( $catId, $ammoCats, true ) )      $defaultType = 'ammo';
		elseif ( in_array( $catId, $opticCats, true ) )     $defaultType = 'optic';
		elseif ( in_array( $catId, $magazineCats, true ) )  $defaultType = 'magazine';
		else                                                 $defaultType = 'accessory';

		$form->add( new \IPS\Helpers\Form\Select( 'gdcatalog_product_type_group', $defaultType, FALSE, [
			'options' => [
				'firearm'   => 'Firearm (Pistol / Rifle)',
				'shotgun'   => 'Shotgun',
				'ammo'      => 'Ammunition',
				'optic'     => 'Optic / Scope',
				'magazine'  => 'Magazine',
				'accessory' => 'Accessory / Other',
			],
			'toggles' => [
				'firearm' => [
					'gdcatalog_product_gun_type', 'gdcatalog_product_action_type',
					'gdcatalog_product_safety_type', 'gdcatalog_product_trigger_type',
					'gdcatalog_product_caliber', 'gdcatalog_product_capacity',
					'gdcatalog_product_barrel_length', 'gdcatalog_product_overall_length',
					'gdcatalog_product_weight_lbs', 'gdcatalog_product_finish',
					'gdcatalog_product_metal_finish', 'gdcatalog_product_frame_finish',
					'gdcatalog_product_stock_material', 'gdcatalog_product_stock_type',
					'gdcatalog_product_sight_type', 'gdcatalog_product_grips',
					'gdcatalog_product_hammer_style', 'gdcatalog_product_receiver_desc',
					'gdcatalog_product_receiver_type', 'gdcatalog_product_frame_material',
					'gdcatalog_product_slide_material', 'gdcatalog_product_features',
				],
				'shotgun' => [
					'gdcatalog_product_action_type', 'gdcatalog_product_gauge',
					'gdcatalog_product_capacity', 'gdcatalog_product_barrel_length',
					'gdcatalog_product_overall_length', 'gdcatalog_product_weight_lbs',
					'gdcatalog_product_choke_config', 'gdcatalog_product_chamber',
					'gdcatalog_product_stock_material', 'gdcatalog_product_finish',
					'gdcatalog_product_receiver_desc', 'gdcatalog_product_features',
				],
				'ammo' => [
					'gdcatalog_product_caliber', 'gdcatalog_product_bullet_type',
					'gdcatalog_product_bullet_weight', 'gdcatalog_product_muzzle_velocity',
					'gdcatalog_product_muzzle_energy', 'gdcatalog_product_rounds_per_box',
					'gdcatalog_product_boxes_per_case', 'gdcatalog_product_casing_material',
					'gdcatalog_product_features',
				],
				'optic' => [
					'gdcatalog_product_magnification', 'gdcatalog_product_objective_mm',
					'gdcatalog_product_reticle', 'gdcatalog_product_tube_diameter',
					'gdcatalog_product_eye_relief', 'gdcatalog_product_finish',
					'gdcatalog_product_weight_lbs', 'gdcatalog_product_features',
				],
				'magazine' => [
					'gdcatalog_product_caliber', 'gdcatalog_product_capacity',
					'gdcatalog_product_finish', 'gdcatalog_product_features',
				],
				'accessory' => [
					'gdcatalog_product_finish', 'gdcatalog_product_weight_lbs',
					'gdcatalog_product_features',
				],
			],
		] ) );

		foreach ( $editableFields as $field => $config )
		{
			$isLocked = $product->isFieldLocked( $field );
			$formClass = $config[0] === 'TextArea'
				? \IPS\Helpers\Form\TextArea::class
				: ( $config[0] === 'Number' ? \IPS\Helpers\Form\Number::class : \IPS\Helpers\Form\Text::class );

			$formField = new $formClass(
				'gdcatalog_product_' . $field,
				$product->$field ?? '',
				FALSE,
				$config[0] === 'Number' ? [ 'decimals' => 2 ] : []
			);

			if ( $isLocked )
			{
				$formField->description = '🔒 LOCKED — Distributor imports cannot overwrite this field.';
			}

			$form->add( $formField );
		}

		/* Boolean flags */
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcatalog_product_nfa_item', $product->nfa_item ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcatalog_product_requires_ffl', $product->requires_ffl ) );
		$form->add( new \IPS\Helpers\Form\YesNo( 'gdcatalog_product_is_ammo', $product->is_ammo ) );

		/* Status */
		$form->add( new \IPS\Helpers\Form\Select(
			'gdcatalog_product_status', $product->record_status, TRUE,
			[ 'options' => [
				'active'       => 'Active',
				'discontinued' => 'Discontinued',
				'admin_review' => 'Admin Review',
				'pending'      => 'Pending',
			]]
		));

		if ( $values = $form->values() )
		{
			\IPS\Session::i()->csrfCheck();

			$newUpc = preg_replace( '/[^0-9]/', '', (string) $values['gdcatalog_product_upc'] );
			$oldUpc = (string) $product->upc;

			if ( $newUpc !== $oldUpc )
			{
				foreach ( $editableFields as $field => $config )
				{
					$product->$field = $values[ 'gdcatalog_product_' . $field ];
				}
				$product->nfa_item      = (int) $values['gdcatalog_product_nfa_item'];
				$product->requires_ffl  = (int) $values['gdcatalog_product_requires_ffl'];
				$product->is_ammo       = (int) $values['gdcatalog_product_is_ammo'];
				$product->record_status = $values['gdcatalog_product_status'];
				$product->last_updated  = date( 'Y-m-d H:i:s' );

				$intColumns   = [ 'rounds_per_box', 'boxes_per_case', 'nfa_item', 'requires_ffl', 'is_ammo' ];
				$floatColumns = [ 'msrp', 'weight_oz' ];
				foreach ( $intColumns as $col )
				{
					if ( isset( $product->$col ) && $product->$col === '' ) { $product->$col = null; }
				}
				foreach ( $floatColumns as $col )
				{
					if ( isset( $product->$col ) && $product->$col === '' ) { $product->$col = null; }
				}

				$allData = [];
				try
				{
					foreach ( \IPS\Db::i()->select( '*', 'gd_catalog', [ 'upc=?', $oldUpc ] )->first() as $col => $val )
					{
						$allData[ $col ] = $val;
					}
				}
				catch ( \Throwable )
				{
					$allData = [];
				}

				foreach ( $editableFields as $field => $config )
				{
					$allData[ $field ] = $product->$field;
				}
				$allData['upc']           = $newUpc;
				$allData['nfa_item']      = $product->nfa_item;
				$allData['requires_ffl']  = $product->requires_ffl;
				$allData['is_ammo']       = $product->is_ammo;
				$allData['record_status'] = $product->record_status;
				$allData['last_updated']  = $product->last_updated;

				try
				{
					\IPS\Db::i()->delete( 'gd_catalog', [ 'upc=?', $oldUpc ] );
					\IPS\Db::i()->insert( 'gd_catalog', $allData );
				}
				catch ( \Throwable $e )
				{
					\IPS\Log::log( $e, 'gdcatalog_upc_edit' );
					\IPS\Output::i()->error( 'gdcatalog_upc_edit_failed', '2GDC/2', 500 );
					return;
				}

				\IPS\Output::i()->redirect(
					\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products' ),
					'gdcatalog_product_saved'
				);
				return;
			}

			foreach ( $editableFields as $field => $config )
			{
				$product->$field = $values[ 'gdcatalog_product_' . $field ];
			}

			$product->nfa_item      = (int) $values['gdcatalog_product_nfa_item'];
			$product->requires_ffl  = (int) $values['gdcatalog_product_requires_ffl'];
			$product->is_ammo       = (int) $values['gdcatalog_product_is_ammo'];
			$product->record_status = $values['gdcatalog_product_status'];
			$product->last_updated  = date( 'Y-m-d H:i:s' );

			$intColumns   = [ 'rounds_per_box', 'boxes_per_case', 'nfa_item', 'requires_ffl', 'is_ammo' ];
			$floatColumns = [ 'msrp', 'weight_oz' ];
			foreach ( $intColumns as $col )
			{
				if ( isset( $product->$col ) && $product->$col === '' ) { $product->$col = null; }
			}
			foreach ( $floatColumns as $col )
			{
				if ( isset( $product->$col ) && $product->$col === '' ) { $product->$col = null; }
			}

			$product->save();

			/* Reindex */
			OpenSearchIndexer::i()->indexProduct( $product );

			\IPS\Output::i()->redirect(
				\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products' ),
				'saved'
			);
		}

		\IPS\Output::i()->title = $product->title . ' (' . $product->upc . ')';

		/* Render a small "Locked Fields" notice above the Form output, if any.
		 * Rule #8: admin form page uses \IPS\Helpers\Form — the form itself is
		 * unchanged; only an informational banner is prepended. No raw-HTML
		 * form markup. */
		/* v1.0.32: Rebuild from Product->locked_fields JSON (the source of
		 * truth that lockField/unlockField actually use). Old code queried
		 * the empty gd_field_locks table. */
		$lockNotice = '';
		if ( !empty( $lockedFieldNames ) )
		{
			$count = count( $lockedFieldNames );
			$lockNotice = '<div class="ipsMessage ipsMessage--warning" style="margin-bottom:16px">'
				. '<div class="ipsBox_body ipsPad">'
				. '<h2 class="ipsType_sectionHead" style="margin:0 0 8px;display:flex;align-items:center;gap:8px">'
				. '<span style="font-size:1.2em">🔒</span>'
				. \IPS\Member::loggedIn()->language()->addToStack( 'gdcatalog_product_locked_fields' )
				. ' <span class="ipsBadge ipsBadge--warning">' . $count . '</span>'
				. '</h2>'
				. '<p style="margin:0 0 12px;color:var(--i-color-text-muted, #666)">These fields will not be overwritten by distributor imports. Any incoming changes create conflicts for admin review.</p>'
				. '<div style="display:flex;flex-wrap:wrap;gap:6px">';

			foreach ( $lockedFieldNames as $fieldName )
			{
				$unlockUrl = (string) \IPS\Http\Url::internal(
					'app=gdcatalog&module=catalog&controller=products&do=unlockField&upc=' . urlencode( (string) $product->upc ) . '&field=' . urlencode( (string) $fieldName )
				)->csrf();

				$lockNotice .= '<span style="display:inline-flex;align-items:center;gap:4px;padding:4px 8px;background:rgba(255,200,0,0.15);border:1px solid rgba(255,180,0,0.4);border-radius:4px;font-size:0.9em">'
					. '<code style="background:none;padding:0">' . htmlspecialchars( (string) $fieldName ) . '</code>'
					. '<a href="' . htmlspecialchars( $unlockUrl ) . '" title="Unlock this field" style="color:#c00;text-decoration:none;font-weight:bold;margin-left:2px">×</a>'
					. '</span>';
			}

			$lockNotice .= '</div></div></div>';
		}

		/* v1.0.19: Image preview block.
		 *
		 * Show the product image at the top of the edit page if image_url
		 * is populated. Helps admins visually identify products when editing,
		 * especially for Sports South imports where the title alone can be
		 * ambiguous.
		 *
		 * Wrapped in try/catch + URL validation so a malformed image_url
		 * doesn't break the entire edit page. Falls back to no preview if
		 * the URL doesn't look valid. */
		$imagePreview = '';
		$imageUrl = trim( (string) ( $product->image_url ?? '' ) );

		if ( $imageUrl !== '' && ( str_starts_with( $imageUrl, 'http://' ) || str_starts_with( $imageUrl, 'https://' ) ) )
		{
			$safeUrl = htmlspecialchars( $imageUrl, ENT_QUOTES, 'UTF-8' );
			$imagePreview = '<div class="ipsBox ipsPull" style="margin-bottom:16px">'
				. '<div class="ipsBox_body ipsPad" style="text-align:center">'
				. '<img src="' . $safeUrl . '" alt="Product image" '
				. 'style="max-width:300px;max-height:300px;border:1px solid var(--i-border-color, #e0e0e0);border-radius:4px;background:#fff;padding:8px" '
				. 'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'block\'">'
				. '<div style="display:none;color:var(--i-color-text-muted, #888);padding:24px">Image could not be loaded</div>'
				. '<div style="margin-top:8px;font-size:0.85em;color:var(--i-color-text-muted, #888)">'
				. '<a href="' . $safeUrl . '" target="_blank" rel="noopener">View full size</a>'
				. '</div>'
				. '</div></div>';
		}

		/* v1.0.31: Lock All / Unlock All Fields buttons. Single-click to lock
		 * every populated field against distributor overwrites, or release
		 * all locks. Rendered as a small action bar above the form. */
		$lockAllUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=products&do=lockAllFields&upc=' . urlencode( (string) $product->upc )
		)->csrf();
		$unlockAllUrl = (string) \IPS\Http\Url::internal(
			'app=gdcatalog&module=catalog&controller=products&do=unlockAllFields&upc=' . urlencode( (string) $product->upc )
		)->csrf();

		$lockButtonBar = '<div class="ipsBox ipsPull" style="margin-bottom:16px">'
			. '<div class="ipsBox_body ipsPad" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
			. '<span style="font-weight:bold;margin-right:8px">Bulk Lock:</span>'
			. '<a href="' . htmlspecialchars( $lockAllUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm title="Lock every populated field so distributor imports cannot overwrite them. Conflicts route to admin review.">Lock All Fields</a>'
			. '<a href="' . htmlspecialchars( $unlockAllUrl ) . '" class="ipsButton ipsButton--secondary ipsButton--small" data-confirm title="Release all field locks for this product.">Unlock All Fields</a>'
			. '</div></div>';

		\IPS\Output::i()->output = $imagePreview . $lockButtonBar . $lockNotice . (string) $form;
	}

	/**
	 * Lock a field on a product.
	 */
	protected function lockField()
	{
		\IPS\Session::i()->csrfCheck();

		$upc   = \IPS\Request::i()->upc;
		$field = \IPS\Request::i()->field;

		$product = Product::load( $upc );
		$product->lockField( $field );
		$product->save();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( $upc ) ),
			'Field locked'
		);
	}

	/**
	 * Unlock a field on a product.
	 */
	protected function unlockField()
	{
		\IPS\Session::i()->csrfCheck();

		$upc   = \IPS\Request::i()->upc;
		$field = \IPS\Request::i()->field;

		$product = Product::load( $upc );
		$product->unlockField( $field );
		$product->save();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( $upc ) ),
			'Field unlocked'
		);
	}

	/**
	 * v1.0.31: Lock every populated field on a product in one click.
	 *
	 * Iterates all editable fields, calls lockField() for each that has a
	 * non-empty value. Useful for "I trust this entire record, don't let
	 * distributors change it."
	 */
	protected function lockAllFields()
	{
		\IPS\Session::i()->csrfCheck();

		$upc = \IPS\Request::i()->upc;

		try
		{
			$product = Product::load( $upc );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->error( 'node_error', '2GDC102/L1', 404 );
			return;
		}

		/* Same field list as add() and edit(). Kept in sync intentionally. */
		$lockable = [
			'title', 'mpn', 'brand', 'manufacturer', 'importer', 'model',
			'gun_type', 'action_type', 'safety_type', 'trigger_type',
			'caliber', 'capacity', 'barrel_length', 'overall_length',
			'weight_lbs', 'gauge', 'finish', 'metal_finish', 'frame_finish',
			'stock_material', 'stock_type', 'sight_type', 'grips',
			'hammer_style', 'choke_config', 'chamber', 'receiver_desc',
			'receiver_type', 'frame_material', 'slide_material',
			'bullet_type', 'bullet_weight', 'muzzle_velocity',
			'muzzle_energy', 'rounds_per_box', 'boxes_per_case',
			'casing_material', 'magnification', 'objective_mm', 'reticle',
			'tube_diameter', 'eye_relief', 'features',
			'image_url', 'msrp', 'description',
		];

		$locked = 0;
		foreach ( $lockable as $field )
		{
			$value = $product->$field ?? '';
			if ( $value !== null && $value !== '' )
			{
				$product->lockField( $field );
				$locked++;
			}
		}

		$product->save();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( $upc ) ),
			sprintf( 'Locked %d populated fields', $locked )
		);
	}

	/**
	 * v1.0.31: Unlock every field on a product in one click.
	 */
	protected function unlockAllFields()
	{
		\IPS\Session::i()->csrfCheck();

		$upc = \IPS\Request::i()->upc;

		try
		{
			$product = Product::load( $upc );
		}
		catch ( \OutOfRangeException )
		{
			\IPS\Output::i()->error( 'node_error', '2GDC102/L2', 404 );
			return;
		}

		$lockable = [
			'title', 'mpn', 'brand', 'manufacturer', 'importer', 'model',
			'gun_type', 'action_type', 'safety_type', 'trigger_type',
			'caliber', 'capacity', 'barrel_length', 'overall_length',
			'weight_lbs', 'gauge', 'finish', 'metal_finish', 'frame_finish',
			'stock_material', 'stock_type', 'sight_type', 'grips',
			'hammer_style', 'choke_config', 'chamber', 'receiver_desc',
			'receiver_type', 'frame_material', 'slide_material',
			'bullet_type', 'bullet_weight', 'muzzle_velocity',
			'muzzle_energy', 'rounds_per_box', 'boxes_per_case',
			'casing_material', 'magnification', 'objective_mm', 'reticle',
			'tube_diameter', 'eye_relief', 'features',
			'image_url', 'msrp', 'description',
		];

		$unlocked = 0;
		foreach ( $lockable as $field )
		{
			if ( $product->isFieldLocked( $field ) )
			{
				$product->unlockField( $field );
				$unlocked++;
			}
		}

		$product->save();

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products&do=edit&upc=' . urlencode( $upc ) ),
			sprintf( 'Unlocked %d fields', $unlocked )
		);
	}

	/**
	 * Admin review queue — resolve a product out of admin_review status.
	 */
	protected function resolveReview()
	{
		\IPS\Session::i()->csrfCheck();

		$upc = \IPS\Request::i()->upc;
		$product = Product::load( $upc );

		$product->record_status = Product::STATUS_ACTIVE;
		$product->last_updated  = date( 'Y-m-d H:i:s' );
		$product->save();

		OpenSearchIndexer::i()->indexProduct( $product );

		\IPS\Output::i()->redirect(
			\IPS\Http\Url::internal( 'app=gdcatalog&module=catalog&controller=products&status=admin_review' ),
			'Product approved'
		);
	}
}

class products extends _products {}
