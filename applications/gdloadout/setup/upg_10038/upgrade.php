<?php

namespace IPS\gdloadout\setup\upg_10038;

use IPS\Db;

class _upgrade
{
	public function step1(): bool
	{
		try
		{
			if ( !Db::i()->checkForColumn( 'gd_loadout_suggestions', 'changes' ) )
			{
				Db::i()->addColumn( 'gd_loadout_suggestions', [
					'name'       => 'changes',
					'type'       => 'TEXT',
					'length'     => 0,
					'allow_null' => true,
					'default'    => null,
				] );
			}
		}
		catch ( \Throwable ) {}

		$newStrings = [
			'gdloadout_suggest_an_edit'          => 'Suggest an Edit',
			'gdloadout_submit_suggestion'        => 'Submit Suggestion',
			'gdloadout_suggest_note'             => 'Note (optional)',
			'gdloadout_suggest_submitted'        => 'Suggestion sent! The owner will be notified.',
			'gdloadout_suggest_changes_required' => 'Make at least one change before submitting.',
		];

		try
		{
			foreach ( Db::i()->select( 'lang_id', 'core_sys_lang' ) as $langId )
			{
				foreach ( $newStrings as $key => $val )
				{
					try
					{
						Db::i()->replace( 'core_sys_lang_words', [
							'lang_id'      => (int) $langId,
							'word_app'     => 'gdloadout',
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

		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
