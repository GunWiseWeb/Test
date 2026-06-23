<?php
namespace IPS\gdrebates\setup\upg_10005;

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
		if ( !\IPS\Db::i()->checkForColumn( 'gd_rebate_sources', 'model_override' ) )
		{
			\IPS\Db::i()->addColumn( 'gd_rebate_sources', [
				'name'           => 'model_override',
				'type'           => 'VARCHAR',
				'length'         => 20,
				'allow_null'     => false,
				'default'        => '',
				'auto_increment' => false,
			] );
		}

		try
		{
			\IPS\Db::i()->replace( 'core_tasks', [
				'app'       => 'gdrebates',
				'key'       => 'ParseRebates',
				'frequency' => 'P0Y0M7DT0H0M0S',
				'running'   => 0,
				'enabled'   => 1,
			] );
		}
		catch ( \Throwable $e ) {}

		if ( !\IPS\Db::i()->checkForTable( 'gd_rebate_logos' ) )
		{
			\IPS\Db::i()->createTable( [
				'name' => 'gd_rebate_logos',
				'columns' => [
					'logo_id'      => [ 'name' => 'logo_id', 'type' => 'BIGINT', 'length' => 20, 'allow_null' => false, 'default' => null, 'auto_increment' => true, 'unsigned' => true ],
					'manufacturer' => [ 'name' => 'manufacturer', 'type' => 'VARCHAR', 'length' => 120, 'allow_null' => false, 'default' => '' ],
					'logo_url'     => [ 'name' => 'logo_url', 'type' => 'VARCHAR', 'length' => 500, 'allow_null' => false, 'default' => '' ],
					'created'      => [ 'name' => 'created', 'type' => 'INT', 'length' => 10, 'allow_null' => false, 'default' => 0, 'unsigned' => true ],
					'updated'      => [ 'name' => 'updated', 'type' => 'INT', 'length' => 10, 'allow_null' => false, 'default' => 0, 'unsigned' => true ],
				],
				'indexes' => [
					'PRIMARY' => [ 'type' => 'primary', 'name' => 'PRIMARY', 'length' => [ null ], 'columns' => [ 'logo_id' ] ],
					'idx_mfr' => [ 'type' => 'key', 'name' => 'idx_mfr', 'length' => [ 50 ], 'columns' => [ 'manufacturer' ] ],
				],
			] );
		}

		$newStrings = [
			'gdrebates_model'                  => 'Default Claude model',
			'gdrebates_src_model_override'     => 'Model override',
			'gdrebates_src_parse'              => 'Parse now',
			'gdrebates_src_parse_done'         => 'Parse complete',
			'menu__gdrebates_rebates_queue'    => 'Review Queue',
			'r__queue_manage'                  => 'Manage rebate review queue',
			'gdrebates_rb_edit'                => 'Edit Rebate',
			'gdrebates_rb_manufacturer'        => 'Manufacturer',
			'gdrebates_rb_title'               => 'Title',
			'gdrebates_rb_rebate_type'         => 'Type',
			'gdrebates_rb_amount'              => 'Amount ($)',
			'gdrebates_rb_amount_text'         => 'Amount label',
			'gdrebates_rb_eligible_models'     => 'Eligible models',
			'gdrebates_rb_start_date'          => 'Start date',
			'gdrebates_rb_end_date'            => 'End date',
			'gdrebates_rb_submit_by'           => 'Submit by',
			'gdrebates_rb_redemption_url'      => 'Redemption URL',
			'gdrebates_rb_status'              => 'Status',
			'gdrebates_type_cash'              => 'Cash back',
			'gdrebates_type_prepaid_card'      => 'Prepaid card',
			'gdrebates_type_store_credit'      => 'Store credit',
			'gdrebates_type_free_item'         => 'Free item',
			'gdrebates_type_free_shipping'     => 'Free shipping',
			'gdrebates_type_bundle'            => 'Bundle',
			'gdrebates_type_other'             => 'Other',
			'gdrebates_status_pending'         => 'Pending',
			'gdrebates_status_approved'        => 'Approved',
			'gdrebates_status_rejected'        => 'Rejected',
			'gdrebates_status_expired'         => 'Expired',
			'gdrebates_status_all'             => 'All',
			'gdrebates_approve'                => 'Approve',
			'gdrebates_reject'                 => 'Reject',
			'gdrebates_approved'               => 'Rebate approved',
			'gdrebates_rejected'               => 'Rebate rejected',
			'gdrebates_page_title'             => 'Manufacturer Rebates',
			'gdrebates_f_all_mfr'              => 'All manufacturers',
			'gdrebates_f_all_type'             => 'All types',
			'gdrebates_f_all_amt'              => 'Any amount',
			'gdrebates_sort_ending'            => 'Ending soon',
			'gdrebates_sort_amount'            => 'Highest $',
			'gdrebates_sort_newest'            => 'Newest',
			'gdrebates_ends'                   => 'Ends',
			'gdrebates_submit'                 => 'Submit Rebate',
			'gdrebates_details'                => 'Details',
			'gdrebates_none'                   => 'No active rebates right now. Check back soon.',
			'gdrebates_no_match'               => 'No rebates match those filters.',
			'menu__gdrebates_rebates_logos'     => 'Manufacturer Logos',
			'r__logos_manage'                  => 'Manage manufacturer logos',
			'gdrebates_logo_manufacturer'      => 'Manufacturer',
			'gdrebates_logo_url'               => 'Logo image URL',
			'gdrebates_logo_add'               => 'Add Logo',
			'gdrebates_logo_edit'              => 'Edit Logo',
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
							'word_app'     => 'gdrebates',
							'word_key'     => $key,
							'word_default' => $val,
							'word_js'      => 0,
							'word_export'  => 1,
						] );
					}
					catch ( \Throwable $e ) {}
				}
			}
		}
		catch ( \Throwable $e ) {}

		try { \IPS\Data\Store::i()->clearAll(); }  catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }  catch ( \Throwable ) {}
		if ( function_exists( 'opcache_reset' ) ) { @opcache_reset(); }

		return TRUE;
	}
}
class upgrade extends _upgrade {}
