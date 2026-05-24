<?php
namespace IPS\gdcatalog\setup\upg_10050;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) { header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' ); exit; }
class _upgrade
{
	public function step1(): bool
	{
		try
		{
			\IPS\Db::i()->query(
				"ALTER TABLE `" . \IPS\Db::i()->prefix . "gd_distributor_feeds` MODIFY COLUMN `auth_type` ENUM('none','basic','apikey','ftp','sportssouth','manual_upload') NOT NULL DEFAULT 'none'"
			);
		}
		catch ( \Throwable ) {}

		try
		{
			\IPS\Db::i()->addColumn( 'gd_distributor_feeds', [
				'name'       => 'uploaded_file_path',
				'type'       => 'VARCHAR',
				'length'     => 500,
				'allow_null' => true,
				'default'    => null,
			] );
		}
		catch ( \Throwable ) {}

		$uploadDir = \IPS\ROOT_PATH . '/uploads/gdcatalog_feeds';
		if ( !is_dir( $uploadDir ) )
		{
			try
			{
				mkdir( $uploadDir, 0755, true );
				file_put_contents( $uploadDir . '/.htaccess', "Deny from all\n" );
			}
			catch ( \Throwable ) {}
		}

		$newStrings = [
			'gdcatalog_upload_feed_title'      => 'Upload Feed File',
			'gdcatalog_upload_success'         => 'Feed file uploaded successfully. Use Run Import to process it.',
			'gdcatalog_upload_no_file'         => 'No file was uploaded or the upload failed.',
			'gdcatalog_upload_invalid_type'    => 'Invalid file type. Only XML, JSON, and CSV files are allowed.',
			'gdcatalog_upload_move_failed'     => 'Failed to save uploaded file. Check server permissions.',
			'gdcatalog_upload_wrong_auth_type' => 'This feed is not configured for manual file upload.',
			'gdcatalog_upload_no_file_stored'  => 'No uploaded file found. Upload a feed file first.',
			'gdcatalog_manual_import_complete' => 'Manual feed import completed successfully.',
			'gdcatalog_manual_import_failed'   => 'Manual feed import failed. Check the error log for details.',
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
					catch ( \Throwable ) {}
				}
			}
		}
		catch ( \Throwable ) {}

		$uploadFormContent = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
	<div class="ipsBox_body ipsPad">
		<h2 style="margin:0 0 16px">Upload Feed File: {$feedName}</h2>
		<p class="ipsType_light" style="margin-bottom:16px">Select an XML, JSON, or CSV feed file to upload. After uploading, use the Run Import button on the feed list to process it.</p>
		<form method="post" action="{$uploadActionUrl}" enctype="multipart/form-data">
			<input type="hidden" name="csrfKey" value="{$csrfKey}">
			<div style="margin-bottom:16px">
				<label style="display:block;font-weight:600;margin-bottom:8px">Feed File (XML, JSON, or CSV)</label>
				<input type="file" name="feed_file" accept=".xml,.json,.csv" required>
			</div>
			<div style="display:flex;gap:8px">
				<button type="submit" class="ipsButton ipsButton--primary">Upload File</button>
				<a href="{$backUrl}" class="ipsButton ipsButton--soft">Cancel</a>
			</div>
		</form>
	</div>
</div>
TEMPLATE_EOT;

		try
		{
			\IPS\Db::i()->replace( 'core_theme_templates', [
				'template_set_id'   => 1,
				'template_app'      => 'gdcatalog',
				'template_location' => 'admin',
				'template_group'    => 'catalog',
				'template_name'     => 'uploadFeedForm',
				'template_data'     => '$uploadActionUrl, $csrfKey, $backUrl, $feedName',
				'template_content'  => $uploadFormContent,
				'template_updated'  => time(),
				'template_version'  => '1.0.50',
			] );
		}
		catch ( \Throwable ) {}

		try { unset( \IPS\Data\Store::i()->extensions ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); } catch ( \Throwable ) {}
		return TRUE;
	}
}
class upgrade extends _upgrade {}
