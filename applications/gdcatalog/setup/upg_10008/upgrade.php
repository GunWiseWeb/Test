<?php
namespace IPS\gdcatalog\setup\upg_10008;

use function defined;

if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) )
{
	header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
	exit;
}

class _upgrade
{
	public function step1(): bool
	{
		/* gdcatalog v1.0.8 - Sports South connection test infrastructure.
		 *
		 * Adds 'sportssouth' as a new auth_type ENUM value on
		 * gd_distributor_feeds. Existing values stay intact:
		 *   ['none', 'basic', 'apikey', 'ftp']  ->  add 'sportssouth'
		 *
		 * The new SportsSouthClient class (sources/Feed/Distributor/SportsSouthClient.php)
		 * handles the POST-form-encoded calls to the Sports South .asmx
		 * endpoint. v1.0.8 only ships the client class and a "Test Connection"
		 * button in feeds.php - no scheduled imports yet, no field mapping yet.
		 *
		 * Lang strings seeded via DB (per CLAUDE.md rule #5/#39 - lang.xml
		 * only runs on fresh install).
		 *
		 * Per CLAUDE.md rule #51: sanity check compares against PREVIOUS
		 * version (10007), not the version being installed (10008).
		 *
		 * This upgrade also reseeds the feedList template to add a
		 * "Test Connection" button rendering for sportssouth-typed feeds.
		 * The template's row data already gets a $feed['test_url'] key from
		 * v1.0.8's feeds.php manage() (non-empty only for sportssouth feeds);
		 * the template renders the button conditionally on that value. */

		/* Step 1: Sanity check the previous version */
		try
		{
			$row = \IPS\Db::i()->select(
				'app_long_version, app_version',
				'core_applications',
				[ 'app_directory=?', 'gdcatalog' ]
			)->first();

			$longVer = (int) ( $row['app_long_version'] ?? 0 );

			$msg = sprintf(
				'gdcatalog v1.0.8 sanity (pre-version-write): app_long_version=%d, app_version=%s',
				$longVer,
				(string) ( $row['app_version'] ?? '' )
			);
			try { \IPS\Log::log( $msg, 'gdcatalog_upg_10008' ); } catch ( \Throwable ) {}

			if ( $longVer < 10007 )
			{
				$warning = sprintf(
					'gdcatalog v1.0.8 WARNING: app_long_version=%d below 10007. Pre-install SQL was likely not run.',
					$longVer
				);
				try { \IPS\Log::log( $warning, 'gdcatalog_upg_10008' ); } catch ( \Throwable ) {}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.8 sanity check failed: ' . $e->getMessage(), 'gdcatalog_upg_10008' ); } catch ( \Throwable ) {}
		}

		/* Step 2: Extend the auth_type ENUM to include 'sportssouth'.
		 * Uses raw ALTER TABLE because IPS\Db::i()->changeColumn doesn't
		 * cleanly handle ENUM value additions without specifying everything. */
		try
		{
			\IPS\Db::i()->query(
				"ALTER TABLE " . \IPS\Db::i()->prefix . "gd_distributor_feeds " .
				"MODIFY COLUMN auth_type ENUM('none','basic','apikey','ftp','sportssouth') " .
				"NOT NULL DEFAULT 'none'"
			);
		}
		catch ( \Throwable $e )
		{
			/* If the value is already there from a prior partial run, that's fine */
			$msg = $e->getMessage();
			if ( !str_contains( $msg, 'sportssouth' ) )
			{
				try { \IPS\Log::log( 'gdcatalog v1.0.8 ALTER TABLE failed: ' . $msg, 'gdcatalog_upg_10008' ); } catch ( \Throwable ) {}
			}
		}

		/* Step 3: Reseed the feedList template to render the Test Connection
		 * button for sportssouth-typed feeds. Without this, $feed['test_url']
		 * passed in by the controller would have nowhere to render and the
		 * button would never appear. */
		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'      => 1,
				'template_app'         => 'gdcatalog',
				'template_location'    => 'admin',
				'template_group'       => 'catalog',
				'template_name'        => 'feedList',
				'template_data'        => '$feeds, $feedCounts, $addUrl, $reorderUrl, $csrfKey',
				'template_content'     => $this->getFeedListTemplate(),
				'template_master_key'  => md5( 'gdcatalog;admin;catalog;feedList' ),
				'template_updated'     => time(),
				'template_version'     => '1.0.8',
			] );
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.8 feedList reseed failed: ' . $e->getMessage(), 'gdcatalog_upg_10008' ); } catch ( \Throwable ) {}
		}

		/* Step 4: Seed lang strings for the test connection UI */
		$newStrings = [
			'gdcatalog_feed_test_connection'            => 'Test Connection',
			'gdcatalog_feed_test_connection_title'      => 'Sports South Connection Test',
			'gdcatalog_feed_test_connection_results'    => 'Connection Test Results',
			'gdcatalog_feed_test_connection_success'    => 'Connection successful',
			'gdcatalog_feed_test_connection_failed'     => 'Connection failed',
			'gdcatalog_feed_auth_type_sportssouth'      => 'Sports South Web Service',
			'gdcatalog_feed_sportssouth_username'       => 'Sports South UserName',
			'gdcatalog_feed_sportssouth_customer'       => 'Sports South CustomerNumber',
			'gdcatalog_feed_sportssouth_password'       => 'Sports South Password',
			'gdcatalog_feed_sportssouth_source'         => 'Source Code (max 6 chars)',
			'gdcatalog_feed_sportssouth_use_test_creds' => 'Use Test Credentials (99994/99994/12345)',
		];

		try
		{
			foreach ( \IPS\Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						\IPS\Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdcatalog',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $rowException )
					{
						try { \IPS\Log::log( 'gdcatalog v1.0.8 lang seed failed key=' . $key . ': ' . $rowException->getMessage(), 'gdcatalog_upg_10008' ); } catch ( \Throwable ) {}
					}
				}
			}
		}
		catch ( \Throwable $e )
		{
			try { \IPS\Log::log( 'gdcatalog v1.0.8 lang seed outer failed: ' . $e->getMessage(), 'gdcatalog_upg_10008' ); } catch ( \Throwable ) {}
		}

		/* Step 5: Cache invalidation */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'extensions%' OR store_key LIKE 'applications%' OR store_key LIKE 'theme_%' OR store_key LIKE 'template_%' OR store_key LIKE 'lang%' OR store_key LIKE 'words%' OR store_key LIKE 'settings%'" ] ); } catch ( \Throwable ) {}

		foreach ( glob( \IPS\ROOT_PATH . '/datastore/extensions*' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/applications*' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f )
		{
			@unlink( $f );
		}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/lang*' ) ?: [] as $f )
		{
			@unlink( $f );
		}

		try { unset( \IPS\Data\Store::i()->extensions );   } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->settings );     } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll();            } catch ( \Throwable ) {}

		return TRUE;
	}

	public function step1CustomTitle()
	{
		return 'gdcatalog v1.0.8 - Sports South API client + Test Connection button';
	}

	/**
	 * v1.0.8 feedList template content. Rebased on v1.0.6 template, adds a
	 * "Test Connection" button rendered conditionally when $feed['test_url']
	 * is non-empty (controller sets it only for sportssouth-typed feeds).
	 *
	 * Per CLAUDE.md rule #28: template content is inlined as nowdoc heredoc,
	 * not extracted at runtime.
	 */
	protected function getFeedListTemplate(): string
	{
		return <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">

		<div style="display:flex;gap:16px;margin-bottom:24px">
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$feedCounts['total']}</div>
				<div>Configured Feeds</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$feedCounts['active']}</div>
				<div>Active Feeds</div>
			</div>
			<div class="ipsBox" style="flex:1;padding:16px;text-align:center">
				<div style="font-size:2em;font-weight:bold">{$feedCounts['urls']}</div>
				<div>URLs Configured</div>
			</div>
		</div>

		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
			<p class="ipsType_light" style="margin:0">{lang="gdcatalog_feeds_help"}</p>
			<a href='{$addUrl}' class="ipsButton ipsButton--primary">
				<i class="fa fa-plus"></i> {lang="gdcatalog_feed_add"}
			</a>
		</div>

		{{if count($feeds) === 0}}
			<div class="ipsEmptyMessage"><p>{lang="gdcatalog_feeds_empty"}</p></div>
		{{else}}
		<table class="ipsTable ipsTable_zebra" style="width:100%" data-controller="gdcatalog.admin.catalog.feedSort" data-reorder-url='{$reorderUrl}' data-csrf-key='{$csrfKey}'>
			<thead>
				<tr>
					<th style="width:30px"></th>
					<th style="width:50px">{lang="gdcatalog_feed_priority"}</th>
					<th>{lang="gdcatalog_feed_name"}</th>
					<th>{lang="gdcatalog_feed_distributor"}</th>
					<th>{lang="gdcatalog_feed_format"}</th>
					<th>{lang="gdcatalog_feed_schedule"}</th>
					<th>{lang="gdcatalog_feed_active"}</th>
					<th>{lang="gdcatalog_feed_last_run"}</th>
					<th>{lang="gdcatalog_feed_last_count"}</th>
					<th>{lang="gdcatalog_feed_last_status"}</th>
					<th style="width:240px">Actions</th>
				</tr>
			</thead>
			<tbody class="gdcatalog-sortable">
				{{foreach $feeds as $feed}}
				<tr data-feed-id='{$feed['id']}'>
					<td style="cursor:move;text-align:center;color:#999" class="gdcatalog-drag-handle" title='{lang="gdcatalog_drag_to_reorder"}'>
						<i class="fa fa-bars"></i>
					</td>
					<td><strong>{$feed['priority']}</strong></td>
					<td><strong>{$feed['feed_name']}</strong></td>
					<td>{$feed['distributor_label']}</td>
					<td>{$feed['feed_format']}</td>
					<td>{$feed['import_schedule']}</td>
					<td>
						{{if $feed['active']}}
							<span class="ipsBadge ipsBadge--positive">{lang="gdcatalog_feed_active"}</span>
						{{else}}
							<span class="ipsBadge ipsBadge--neutral">Inactive</span>
						{{endif}}
					</td>
					<td>
						{{if $feed['last_run']}}
							{$feed['last_run']}
						{{else}}
							&mdash;
						{{endif}}
					</td>
					<td>{expression="number_format( $feed['last_record_count'] )"}</td>
					<td>
						{{if $feed['last_run_status'] === 'completed'}}
							<span class="ipsBadge ipsBadge--positive">OK</span>
						{{elseif $feed['last_run_status'] === 'failed'}}
							<span class="ipsBadge ipsBadge--negative">Failed</span>
						{{elseif $feed['last_run_status'] === 'running'}}
							<span class="ipsBadge ipsBadge--warning">Running</span>
						{{else}}
							&mdash;
						{{endif}}
					</td>
					<td>
						<a href='{$feed['edit_url']}' class="ipsButton ipsButton--primary ipsButton--small">{lang="gdcatalog_btn_edit"}</a>
						<a href='{$feed['delete_url']}' class="ipsButton ipsButton--negative ipsButton--small" data-confirm-message='{lang="gdcatalog_feed_delete_confirm"}'>{lang="gdcatalog_btn_delete"}</a>
						{{if $feed['test_url']}}
						<a href='{$feed['test_url']}' class="ipsButton ipsButton--alternate ipsButton--small">{lang="gdcatalog_feed_test_connection"}</a>
						{{endif}}
					</td>
				</tr>
				{{endforeach}}
			</tbody>
		</table>
		{{endif}}

	</div>
</div>
TEMPLATE_EOT;
	}
}

class upgrade extends _upgrade {}
