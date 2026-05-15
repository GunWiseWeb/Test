<?php
/**
 * v1.0.196: Sync git source with prod DB state.
 *
 * DB-ONLY operations:
 *   - CREATE TABLE IF NOT EXISTS for 10 missing tables
 *   - ALTER TABLE gd_unmatched_upcs: add status, snapshot_json, dealer_reported_at
 *   - Seed dashboardCategories template
 *   - Seed gddealer_front_tab_categories lang string
 *   - Clear caches
 *
 * NO file_put_contents. NO str_replace on .php files. NO array_splice.
 */

namespace IPS\gddealer\setup\upg_10196;

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
		$prefix = \IPS\Db::i()->prefix;

		/* --- gd_dealer_support_departments --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_support_departments' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_dealer_support_departments',
					'columns' => [
						[ 'name' => 'id',          'type' => 'INT',      'length' => 10,  'unsigned' => TRUE, 'allow_null' => FALSE, 'auto_increment' => TRUE ],
						[ 'name' => 'name',        'type' => 'VARCHAR',  'length' => 128, 'allow_null' => FALSE ],
						[ 'name' => 'description', 'type' => 'TEXT',     'allow_null' => TRUE,  'default' => NULL ],
						[ 'name' => 'email',       'type' => 'VARCHAR',  'length' => 255, 'allow_null' => TRUE,  'default' => NULL ],
						[ 'name' => 'visibility',  'type' => 'VARCHAR',  'length' => 16,  'allow_null' => FALSE, 'default' => 'pro' ],
						[ 'name' => 'position',    'type' => 'INT',      'length' => 10,  'allow_null' => FALSE, 'default' => 0 ],
						[ 'name' => 'enabled',     'type' => 'TINYINT',  'length' => 1,   'allow_null' => FALSE, 'default' => 1 ],
						[ 'name' => 'created_at',  'type' => 'DATETIME', 'allow_null' => FALSE ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',  'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'position', 'columns' => [ 'position' ] ],
						[ 'type' => 'key',     'name' => 'enabled',  'columns' => [ 'enabled' ] ],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_dealer_support_tickets --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_support_tickets' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_dealer_support_tickets',
					'columns' => [
						[ 'name' => 'id',              'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'allow_null' => FALSE, 'auto_increment' => TRUE ],
						[ 'name' => 'department_id',   'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'dealer_id',       'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'member_id',       'type' => 'BIGINT',     'length' => 20,  'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'subject',         'type' => 'VARCHAR',    'length' => 255, 'allow_null' => FALSE ],
						[ 'name' => 'priority',        'type' => 'VARCHAR',    'length' => 16,  'allow_null' => FALSE, 'default' => 'normal' ],
						[ 'name' => 'status',          'type' => 'VARCHAR',    'length' => 32,  'allow_null' => FALSE, 'default' => 'open' ],
						[ 'name' => 'body',            'type' => 'MEDIUMTEXT', 'allow_null' => FALSE ],
						[ 'name' => 'assigned_to',     'type' => 'BIGINT',     'length' => 20,  'unsigned' => TRUE, 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'created_at',      'type' => 'DATETIME',   'allow_null' => FALSE ],
						[ 'name' => 'updated_at',      'type' => 'DATETIME',   'allow_null' => FALSE ],
						[ 'name' => 'last_reply_at',   'type' => 'DATETIME',   'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'last_reply_by',   'type' => 'BIGINT',     'length' => 20,  'unsigned' => TRUE, 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'last_reply_role', 'type' => 'VARCHAR',    'length' => 16,  'allow_null' => TRUE, 'default' => NULL ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',       'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'department_id', 'columns' => [ 'department_id' ] ],
						[ 'type' => 'key',     'name' => 'dealer_id',     'columns' => [ 'dealer_id' ] ],
						[ 'type' => 'key',     'name' => 'member_id',     'columns' => [ 'member_id' ] ],
						[ 'type' => 'key',     'name' => 'status',        'columns' => [ 'status' ] ],
						[ 'type' => 'key',     'name' => 'updated_at',    'columns' => [ 'updated_at' ] ],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_dealer_support_replies --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_support_replies' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_dealer_support_replies',
					'columns' => [
						[ 'name' => 'id',             'type' => 'INT',        'length' => 10, 'unsigned' => TRUE, 'allow_null' => FALSE, 'auto_increment' => TRUE ],
						[ 'name' => 'ticket_id',      'type' => 'INT',        'length' => 10, 'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'member_id',      'type' => 'BIGINT',     'length' => 20, 'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'role',           'type' => 'VARCHAR',    'length' => 16, 'allow_null' => FALSE ],
						[ 'name' => 'body',           'type' => 'MEDIUMTEXT', 'allow_null' => FALSE ],
						[ 'name' => 'is_hidden_note', 'type' => 'TINYINT',    'length' => 1,  'allow_null' => FALSE, 'default' => 0 ],
						[ 'name' => 'created_at',     'type' => 'DATETIME',   'allow_null' => FALSE ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',    'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'ticket_id',  'columns' => [ 'ticket_id' ] ],
						[ 'type' => 'key',     'name' => 'created_at', 'columns' => [ 'created_at' ] ],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_dealer_support_events --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_support_events' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_dealer_support_events',
					'columns' => [
						[ 'name' => 'id',         'type' => 'INT',      'length' => 10,  'unsigned' => TRUE, 'allow_null' => FALSE, 'auto_increment' => TRUE ],
						[ 'name' => 'ticket_id',  'type' => 'INT',      'length' => 10,  'unsigned' => TRUE, 'allow_null' => FALSE ],
						[ 'name' => 'event_type', 'type' => 'VARCHAR',  'length' => 64,  'allow_null' => FALSE ],
						[ 'name' => 'actor_id',   'type' => 'BIGINT',   'length' => 20,  'unsigned' => TRUE, 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'actor_role', 'type' => 'VARCHAR',  'length' => 16,  'allow_null' => FALSE, 'default' => 'system' ],
						[ 'name' => 'note',       'type' => 'TEXT',     'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'old_value',  'type' => 'VARCHAR',  'length' => 64,  'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'new_value',  'type' => 'VARCHAR',  'length' => 64,  'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'created_at', 'type' => 'DATETIME', 'allow_null' => FALSE ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'name' => 'PRIMARY',    'columns' => [ 'id' ] ],
						[ 'type' => 'key',     'name' => 'ticket_id',  'columns' => [ 'ticket_id' ] ],
						[ 'type' => 'key',     'name' => 'created_at', 'columns' => [ 'created_at' ] ],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_dealer_support_stock_replies --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_support_stock_replies' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_dealer_support_stock_replies',
					'columns' => [
						[ 'name' => 'id',            'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'auto_increment' => TRUE ],
						[ 'name' => 'title',         'type' => 'VARCHAR',    'length' => 255 ],
						[ 'name' => 'body',          'type' => 'MEDIUMTEXT' ],
						[ 'name' => 'department_id', 'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'position',      'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'default' => 0 ],
						[ 'name' => 'enabled',       'type' => 'TINYINT',    'length' => 1,   'unsigned' => TRUE, 'default' => 1 ],
						[ 'name' => 'created_at',    'type' => 'DATETIME',   'allow_null' => TRUE, 'default' => NULL ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'columns' => [ 'id' ] ],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_dealer_support_stock_actions --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_support_stock_actions' ) )
			{
				\IPS\Db::i()->createTable( [
					'name'    => 'gd_dealer_support_stock_actions',
					'columns' => [
						[ 'name' => 'id',            'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'auto_increment' => TRUE ],
						[ 'name' => 'title',         'type' => 'VARCHAR',    'length' => 255 ],
						[ 'name' => 'reply_body',    'type' => 'MEDIUMTEXT', 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'new_status',    'type' => 'VARCHAR',    'length' => 32,  'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'new_priority',  'type' => 'VARCHAR',    'length' => 16,  'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'new_assignee',  'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'department_id', 'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'allow_null' => TRUE, 'default' => NULL ],
						[ 'name' => 'position',      'type' => 'INT',        'length' => 10,  'unsigned' => TRUE, 'default' => 0 ],
						[ 'name' => 'enabled',       'type' => 'TINYINT',    'length' => 1,   'unsigned' => TRUE, 'default' => 1 ],
						[ 'name' => 'created_at',    'type' => 'DATETIME',   'allow_null' => TRUE, 'default' => NULL ],
					],
					'indexes' => [
						[ 'type' => 'primary', 'columns' => [ 'id' ] ],
					],
				] );
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_dealer_slug_history --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_slug_history' ) )
			{
				\IPS\Db::i()->query(
					"CREATE TABLE `{$prefix}gd_dealer_slug_history` (
						`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
						`old_slug` varchar(100) NOT NULL,
						`dealer_id` int(10) unsigned NOT NULL,
						`retired_at` datetime NOT NULL,
						`retired_reason` varchar(50) NOT NULL DEFAULT 'manual_regenerate',
						PRIMARY KEY (`id`),
						UNIQUE KEY `uq_old_slug` (`old_slug`),
						KEY `idx_dealer_id` (`dealer_id`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_click_log --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_click_log' ) )
			{
				\IPS\Db::i()->query(
					"CREATE TABLE `{$prefix}gd_click_log` (
						`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`dealer_id` int(10) unsigned NOT NULL,
						`upc` varchar(20) NOT NULL DEFAULT '',
						`member_id` bigint(20) unsigned DEFAULT NULL,
						`user_state` varchar(2) DEFAULT NULL,
						`clicked_at` datetime NOT NULL,
						PRIMARY KEY (`id`),
						KEY `idx_dealer_id` (`dealer_id`),
						KEY `idx_upc` (`upc`),
						KEY `idx_clicked_at` (`clicked_at`),
						KEY `idx_member_dealer_upc` (`member_id`,`dealer_id`,`upc`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_click_daily --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_click_daily' ) )
			{
				\IPS\Db::i()->query(
					"CREATE TABLE `{$prefix}gd_click_daily` (
						`id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
						`dealer_id` int(10) unsigned NOT NULL,
						`click_date` date NOT NULL,
						`click_count` int(10) unsigned NOT NULL DEFAULT 0,
						PRIMARY KEY (`id`),
						UNIQUE KEY `uq_dealer_date` (`dealer_id`,`click_date`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);
			}
		}
		catch ( \Throwable ) {}

		/* --- gd_dealer_reset_backups --- */
		try
		{
			if ( !\IPS\Db::i()->checkForTable( 'gd_dealer_reset_backups' ) )
			{
				\IPS\Db::i()->query(
					"CREATE TABLE `{$prefix}gd_dealer_reset_backups` (
						`id` int(10) unsigned NOT NULL AUTO_INCREMENT,
						`dealer_id` int(10) unsigned NOT NULL,
						`scope` varchar(4) NOT NULL DEFAULT 'B',
						`snapshot_json` longtext,
						`created_at` datetime NOT NULL,
						PRIMARY KEY (`id`),
						KEY `idx_dealer` (`dealer_id`),
						KEY `idx_created` (`created_at`)
					) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
				);
			}
		}
		catch ( \Throwable ) {}

		return TRUE;
	}

	public function step2(): bool
	{
		/* ALTER TABLE gd_unmatched_upcs: add 3 missing columns */
		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_unmatched_upcs', 'status' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_unmatched_upcs', [
					'name'       => 'status',
					'type'       => 'VARCHAR',
					'length'     => 32,
					'allow_null' => FALSE,
					'default'    => 'pending',
				] );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_unmatched_upcs', 'snapshot_json' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_unmatched_upcs', [
					'name'       => 'snapshot_json',
					'type'       => 'LONGTEXT',
					'allow_null' => TRUE,
					'default'    => NULL,
				] );
			}
		}
		catch ( \Throwable ) {}

		try
		{
			if ( !\IPS\Db::i()->checkForColumn( 'gd_unmatched_upcs', 'dealer_reported_at' ) )
			{
				\IPS\Db::i()->addColumn( 'gd_unmatched_upcs', [
					'name'       => 'dealer_reported_at',
					'type'       => 'DATETIME',
					'allow_null' => TRUE,
					'default'    => NULL,
				] );
			}
		}
		catch ( \Throwable ) {}

		return TRUE;
	}

	public function step3(): bool
	{
		/* Seed dashboardCategories template */
		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'  => 1,
				'template_app'     => 'gddealer',
				'template_location'=> 'front',
				'template_group'   => 'dealers',
				'template_name'    => 'dashboardCategories',
				'template_data'    => '$data',
				'template_updated' => time(),
				'template_version' => '1.0.196',
				'template_content' => <<<'TEMPLATE_EOT'
<div class="gdDashboard">
    <div class="gdDashboard__shell">

        <div class="gdDashboard__body">

            <h1 class="gdDashboard__title">Category Mappings</h1>

            <p class="gdDashboard__lede">
                Your feed uses categories like "Firearms, Handguns, Pistols". We map them to one of our
                canonical categories so search and filtering work consistently. Edit any mapping below;
                set a category to "skip" to drop those records from your listings.
            </p>

            {{if $data['saved_flash']}}
            <div class="gdAlert gdAlert--success">Mappings saved.</div>
            {{endif}}

            {{if $data['unreviewed'] > 0}}
            <div class="gdAlert gdAlert--info">
                <strong>{$data['unreviewed']}</strong> mapping(s) were auto-classified and haven't been reviewed yet. Confirm or change them below — any edit you save will mark that row as reviewed.
            </div>
            {{endif}}

            {{if $data['by_canonical']}}
            <div class="gdCatSummary">
                {{foreach $data['by_canonical'] as $cat => $info}}
                <div class="gdCatSummary__pill">
                    <span class="gdCatSummary__name">{$cat}</span>
                    <span class="gdCatSummary__count">{$info['count']} records · {$info['rows']} rules</span>
                </div>
                {{endforeach}}
            </div>
            {{endif}}

            {{if count( $data['mappings'] ) === 0}}
                <div class="gdEmpty">
                    <p>No category mappings yet. Run your next import and they'll populate automatically.</p>
                </div>
            {{else}}
                <form method="post" action="{$data['save_url']}" class="gdCatForm">
                    <input type="hidden" name="csrfKey" value="{$data['csrf_key']}">

                    <table class="gdCatTable">
                        <thead>
                            <tr>
                                <th class="gdCatTable__source">Your category</th>
                                <th class="gdCatTable__canonical">Maps to</th>
                                <th class="gdCatTable__count">Records</th>
                                <th class="gdCatTable__status">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            {{foreach $data['mappings'] as $m}}
                            <tr class="{{if $m['auto_mapped']}}gdCatTable__row--auto{{endif}}">
                                <td class="gdCatTable__source">
                                    <span class="gdCatTable__sourceText">{$m['source_value']}</span>
                                </td>
                                <td class="gdCatTable__canonical">
                                    <select name="canonical[{$m['id']}]" class="gdCatTable__select">
                                        {{foreach $data['valid_options'] as $opt}}
                                        <option value="{$opt}" {{if $m['canonical'] === $opt}}selected{{endif}}>{$opt}</option>
                                        {{endforeach}}
                                    </select>
                                </td>
                                <td class="gdCatTable__count">{$m['occurrence_count']}</td>
                                <td class="gdCatTable__status">
                                    {{if $m['auto_mapped']}}
                                        <span class="gdCatTable__badge gdCatTable__badge--auto">auto</span>
                                    {{else}}
                                        <span class="gdCatTable__badge gdCatTable__badge--confirmed">confirmed</span>
                                    {{endif}}
                                </td>
                            </tr>
                            {{endforeach}}
                        </tbody>
                    </table>

                    <div class="gdCatForm__actions">
                        <button type="submit" class="gdBtn gdBtn--primary">Save Changes</button>
                    </div>
                </form>
            {{endif}}

        </div>
    </div>
</div>

<style>
.gdDashboard__shell { max-width: 1200px; margin: 0 auto; padding: 24px; font-family: 'Inter', system-ui, sans-serif; }
.gdDashboard__title { font-size: 28px; font-weight: 700; margin: 0 0 8px; color: #111827; }
.gdDashboard__lede { color: #6b7280; margin: 0 0 24px; max-width: 760px; line-height: 1.6; }
.gdAlert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; }
.gdAlert--success { background: #d1fae5; color: #065f46; border: 1px solid #34d399; }
.gdAlert--info { background: #dbeafe; color: #1e40af; border: 1px solid #60a5fa; }
.gdCatSummary { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 24px; }
.gdCatSummary__pill { background: #f3f4f6; padding: 8px 14px; border-radius: 999px; font-size: 13px; }
.gdCatSummary__name { font-weight: 600; color: #111827; text-transform: capitalize; }
.gdCatSummary__count { color: #6b7280; margin-left: 6px; }
.gdEmpty { padding: 48px; text-align: center; background: #f9fafb; border-radius: 12px; color: #6b7280; }
.gdCatForm { background: #fff; border-radius: 12px; border: 1px solid #e5e7eb; overflow: hidden; }
.gdCatTable { width: 100%; border-collapse: collapse; }
.gdCatTable th { background: #f9fafb; padding: 12px 16px; text-align: left; font-weight: 600; font-size: 13px; color: #374151; border-bottom: 1px solid #e5e7eb; }
.gdCatTable td { padding: 12px 16px; border-bottom: 1px solid #f3f4f6; }
.gdCatTable__row--auto { background: #fffbeb; }
.gdCatTable__sourceText { font-family: 'JetBrains Mono', monospace; font-size: 13px; color: #1f2937; }
.gdCatTable__select { padding: 6px 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px; }
.gdCatTable__count { color: #6b7280; font-variant-numeric: tabular-nums; }
.gdCatTable__badge { padding: 3px 8px; border-radius: 4px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
.gdCatTable__badge--auto { background: #fef3c7; color: #92400e; }
.gdCatTable__badge--confirmed { background: #d1fae5; color: #065f46; }
.gdCatForm__actions { padding: 16px 20px; background: #f9fafb; border-top: 1px solid #e5e7eb; display: flex; justify-content: flex-end; }
.gdBtn { padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; border: none; cursor: pointer; }
.gdBtn--primary { background: #1e40af; color: #fff; }
.gdBtn--primary:hover { background: #1e3a8a; }
</style>
TEMPLATE_EOT,
			] );
		}
		catch ( \Throwable ) {}

		/* Seed lang string */
		$newStrings = [
			'gddealer_front_tab_categories' => 'Categories',
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
							'word_app'     => 'gddealer',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		/* Cache clears */
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->modules ); }      catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}
		try { \IPS\Data\Store::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
