<div class="gdFeedSettings">

	{{if $data['wizard_done_flash']}}
	<div class="gdFeedSettings__flash gdFeedSettings__flash--success">
		<strong>&#10003; Setup wizard complete!</strong>
		Your feed configuration has been saved. The import scheduler will pick it up on its next run.
		<p>This page is now your home base for ongoing feed management. Upload new feed files (manual mode), check import history, and verify your sync health. To reconfigure your feed source or field mapping, <a href="{$data['wizard_url']}">re-run the Setup Wizard</a>.</p>
	</div>
	{{endif}}

	<div class="gdFeedSettings__guide">
		<div class="gdFeedSettings__guideIcon">&#9432;</div>
		<div class="gdFeedSettings__guideBody">
			<strong>How this page works</strong>
			<p>This is your <em>read-only</em> feed configuration view. To change your feed URL, format, authentication, or field mappings, use the <a href="{$data['wizard_url']}">Setup Wizard</a>. To upload a feed file (manual mode only) or check upload history, scroll down.</p>
		</div>
	</div>

	<section class="gdFeedSettings__healthCard gdFeedSettings__healthCard--{$data['sync_health']}">
		<div class="gdFeedSettings__healthHeader">
			<h3>{$data['sync_title']}</h3>
			<p>{$data['sync_sub']}</p>
		</div>
	</section>

	<section class="gdFeedSettings__card">
		<div class="gdFeedSettings__cardHead">
			<h4>Current Configuration</h4>
			<a href="{$data['wizard_url']}" class="gdFeedSettings__btn gdFeedSettings__btn--ghost">Re-run Setup Wizard</a>
		</div>
		<table class="gdFeedSettings__configTable">
			<tr>
				<th>Delivery mode</th>
				<td>
					{{if $data['delivery_mode'] === 'manual'}}
						<span class="gdFeedSettings__pill gdFeedSettings__pill--manual">Manual upload</span>
						<span class="gdFeedSettings__hint">You upload feed files when your data changes.</span>
					{{else}}
						<span class="gdFeedSettings__pill gdFeedSettings__pill--url">Hosted URL</span>
						<span class="gdFeedSettings__hint">System polls your feed URL on a schedule.</span>
					{{endif}}
				</td>
			</tr>
			{{if $data['delivery_mode'] === 'url'}}
			<tr>
				<th>Feed URL</th>
				<td>
					{{if $data['feed_url'] !== ''}}
						<code class="gdFeedSettings__code gdFeedSettings__code--url">{$data['feed_url']}</code>
					{{else}}
						<span class="gdFeedSettings__missing">Not configured &mdash; <a href="{$data['wizard_url']}">run the Setup Wizard</a></span>
					{{endif}}
				</td>
			</tr>
			{{endif}}
			<tr>
				<th>Format</th>
				<td>
					{{if $data['feed_format'] !== ''}}
						<code class="gdFeedSettings__code">{$data['feed_format']}</code>
					{{else}}
						<span class="gdFeedSettings__missing">Not set</span>
					{{endif}}
				</td>
			</tr>
			{{if $data['delivery_mode'] === 'url'}}
			<tr>
				<th>Authentication</th>
				<td>
					{{if $data['auth_type'] === 'none'}}
						<span class="gdFeedSettings__pill gdFeedSettings__pill--neutral">None</span>
					{{else}}
						<span class="gdFeedSettings__pill gdFeedSettings__pill--neutral">{$data['auth_type']}</span>
						{{if $data['has_credentials']}}
							<span class="gdFeedSettings__pill gdFeedSettings__pill--good">Credentials saved</span>
						{{else}}
							<span class="gdFeedSettings__pill gdFeedSettings__pill--warn">No credentials</span>
						{{endif}}
					{{endif}}
				</td>
			</tr>
			{{endif}}
			<tr>
				<th>Field mapping</th>
				<td>
					{{if $data['mapped_count'] > 0 || $data['default_count'] > 0}}
						<span class="gdFeedSettings__pill gdFeedSettings__pill--good">{$data['mapped_count']} feed fields mapped</span>
						{{if $data['default_count'] > 0}}
							<span class="gdFeedSettings__pill gdFeedSettings__pill--info">{$data['default_count']} defaults applied</span>
						{{endif}}
					{{else}}
						<span class="gdFeedSettings__missing">No mapping yet &mdash; <a href="{$data['wizard_url']}">run the Setup Wizard</a></span>
					{{endif}}
				</td>
			</tr>
			{{if $data['wizard_completed_at'] !== ''}}
			<tr>
				<th>Wizard completed</th>
				<td><span class="gdFeedSettings__hint">{$data['wizard_completed_at']}</span></td>
			</tr>
			{{endif}}
		</table>
	</section>

	{{if $data['delivery_mode'] === 'manual'}}
	<section class="gdFeedSettings__card">
		<h4>Upload Feed File</h4>
		<p>Drop a CSV, XML, JSON, TSV, or TXT file here. The next import cycle will pick it up automatically.</p>
		<form method="post" action="{$data['upload_url']}" enctype="multipart/form-data" class="gdFeedSettings__uploadForm">
			<div class="gdFeedSettings__uploadDrop">
				<input type="file" name="gddealer_front_feed_file" id="gd_feed_file" accept=".csv,.xml,.json,.tsv,.txt" required>
				<label for="gd_feed_file" class="gdFeedSettings__uploadLabel">
					<span class="gdFeedSettings__uploadIcon">&#8682;</span>
					<span>Click to choose a file or drag here</span>
				</label>
			</div>
			<div class="gdFeedSettings__uploadActions">
				<button type="submit" class="gdFeedSettings__btn gdFeedSettings__btn--primary">Upload Feed File</button>
			</div>
		</form>
	</section>
	{{endif}}

	{{if count($data['recent_uploads']) > 0}}
	<section class="gdFeedSettings__card">
		<h4>Recent Uploads</h4>
		<table class="gdFeedSettings__listTable">
			<thead>
				<tr>
					<th>File</th>
					<th>Format</th>
					<th>Size</th>
					<th>When</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $data['recent_uploads'] as $u}}
					{{$sizeKb = round( $u['file_size_bytes'] / 1024 );}}
					<tr>
						<td><code class="gdFeedSettings__code">{$u['file_name']}</code></td>
						<td><span class="gdFeedSettings__pill gdFeedSettings__pill--neutral">{$u['upload_format']}</span></td>
						<td>{$sizeKb} KB</td>
						<td>{$u['uploaded_ago']}</td>
					</tr>
				{{endforeach}}
			</tbody>
		</table>
	</section>
	{{endif}}

	{{if count($data['import_log']) > 0}}
	<section class="gdFeedSettings__card">
		<h4>Recent Imports</h4>
		<table class="gdFeedSettings__listTable">
			<thead>
				<tr>
					<th>When</th>
					<th>Status</th>
					<th>Records</th>
					<th>New / Updated / Unmatched</th>
				</tr>
			</thead>
			<tbody>
				{{foreach $data['import_log'] as $log}}
					{{if $log['status'] === 'success'}}
						{{$logCls = 'gdFeedSettings__pill--good';}}
					{{elseif $log['status'] === 'failed'}}
						{{$logCls = 'gdFeedSettings__pill--bad';}}
					{{elseif $log['status'] === 'partial'}}
						{{$logCls = 'gdFeedSettings__pill--warn';}}
					{{else}}
						{{$logCls = 'gdFeedSettings__pill--neutral';}}
					{{endif}}
					<tr>
						<td>{$log['when_ago']}</td>
						<td><span class="gdFeedSettings__pill {$logCls}">{$log['status']}</span></td>
						<td>{$log['records']}</td>
						<td><span class="gdFeedSettings__hint">{$log['new']} / {$log['updated']} / {$log['unmatched']}</span></td>
					</tr>
				{{endforeach}}
			</tbody>
		</table>
	</section>
	{{endif}}

</div>