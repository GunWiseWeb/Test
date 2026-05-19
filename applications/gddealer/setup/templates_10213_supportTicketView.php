<?php
namespace IPS\gddealer\setup;
use function defined;
if ( !defined( '\IPS\SUITE_UNIQUE_KEY' ) ) {
    header( ( $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.0' ) . ' 403 Forbidden' );
    exit;
}

$supportTicketViewTpl = <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">
<div style="margin-bottom:16px"><a href="{$back_url}" style="color:#2563eb;text-decoration:none;font-size:0.9em">&larr; Back to tickets</a></div>
<div style="display:flex;gap:24px;flex-wrap:wrap">
<div style="flex:1;min-width:400px">
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:20px">
		<div style="padding:16px 20px;border-bottom:1px solid #f0f0f0">
			<h2 style="margin:0 0 8px;font-size:1.2em;font-weight:700">{$ticket['subject']}</h2>
			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;font-size:0.85em;color:#6b7280">
				<span style="background:{$ticket['status_bg']};color:{$ticket['status_color']};padding:2px 10px;border-radius:12px;font-weight:600;font-size:0.85em">{$ticket['status_label']}</span>
				<span style="background:{$ticket['priority_bg']};color:{$ticket['priority_color']};padding:2px 10px;border-radius:12px;font-weight:600;font-size:0.85em">{$ticket['priority_label']}</span>
				<span>#{$ticket['id']}</span>
				<span>&middot; {$ticket['created_at']}</span>
			</div>
		</div>
		<div style="padding:16px 20px">
			{$ticket_body|raw}
			{{if count($ticket_attachments) > 0}}
			<div style="margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f0">
				<div style="font-size:0.8em;font-weight:600;color:#475569;margin-bottom:6px">Attachments</div>
				{{foreach $ticket_attachments as $att}}
				<div style="margin-bottom:4px"><a href="{$att['url']}" target="_blank" style="font-size:0.85em;color:#2563eb">{$att['filename']}</a></div>
				{{endforeach}}
			</div>
			{{endif}}
		</div>
	</div>
	{{if count($replies) > 0}}
	<h3 id="replies" style="font-size:1em;font-weight:700;margin:0 0 12px">Replies</h3>
	{{foreach $replies as $r}}
	{{if $r['is_hidden_note']}}
	<div style="background:#fffbeb;border:1px solid #fcd34d;border-left:3px solid #f59e0b;border-radius:8px;margin-bottom:10px">
		<div style="padding:8px 14px;background:#fef3c7;border-bottom:1px solid #fde68a;display:flex;align-items:center;gap:8px;font-size:0.85em">
			<span style="background:{$r['role_bg']};color:{$r['role_color']};padding:1px 8px;border-radius:10px;font-weight:600;font-size:0.8em"><i class="fa-solid fa-lock" aria-hidden="true"></i> {$r['role_label']}</span>
			<span style="font-weight:600">{$r['author_name']}</span>
			<span style="color:#6b7280">&middot; {$r['created_at']}</span>
		</div>
		<div style="padding:12px 14px">{$r['body']|raw}</div>
	</div>
	{{else}}
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:10px;border-left:3px solid {$r['role_color']}">
		<div style="padding:8px 14px;background:#f8fafc;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:8px;font-size:0.85em">
			<span style="background:{$r['role_bg']};color:{$r['role_color']};padding:1px 8px;border-radius:10px;font-weight:600;font-size:0.8em">{$r['role_label']}</span>
			<span style="font-weight:600">{$r['author_name']}</span>
			<span style="color:#6b7280">&middot; {$r['created_at']}</span>
		</div>
		<div style="padding:12px 14px">{$r['body']|raw}</div>
	</div>
	{{endif}}
	{{endforeach}}
	{{endif}}
	<div style="margin-top:20px">
		{{if $ticket['status'] === 'closed'}}
		<div style="background:#fffbeb;border:1px solid #fbbf24;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.88em;color:#92400e"><strong>This ticket is closed.</strong> Replying will reopen it and notify the dealer. Notes don't reopen or notify.</div>
		{{endif}}
		{{if $ticket['status'] === 'resolved'}}
		<div style="background:#eff6ff;border:1px solid #60a5fa;border-radius:6px;padding:10px 14px;margin-bottom:14px;font-size:0.88em;color:#1e40af"><strong>This ticket is resolved.</strong> Replying will reopen it and notify the dealer. Notes don't reopen or notify.</div>
		{{endif}}
		<div style="display:flex;gap:4px;border-bottom:1px solid #e5e7eb;margin-bottom:-1px">
			<button type="button" id="gd-tab-btn-reply" onclick="document.getElementById('gd-tab-reply').style.display='block';document.getElementById('gd-tab-note').style.display='none';this.style.background='#fff';this.style.borderBottomColor='#fff';var o=document.getElementById('gd-tab-btn-note');o.style.background='transparent';o.style.borderBottomColor='#e5e7eb';" style="padding:10px 18px;background:#fff;border:1px solid #e5e7eb;border-bottom:1px solid #fff;border-radius:8px 8px 0 0;font-size:13px;font-weight:500;color:#111827;cursor:pointer;position:relative;bottom:-1px"><i class="fa-solid fa-reply" aria-hidden="true"></i> Reply to dealer</button>
			<button type="button" id="gd-tab-btn-note" onclick="document.getElementById('gd-tab-reply').style.display='none';document.getElementById('gd-tab-note').style.display='block';this.style.background='#fff';this.style.borderBottomColor='#fff';var o=document.getElementById('gd-tab-btn-reply');o.style.background='transparent';o.style.borderBottomColor='#e5e7eb';" style="padding:10px 18px;background:transparent;border:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;border-radius:8px 8px 0 0;font-size:13px;font-weight:500;color:#854d0e;cursor:pointer;position:relative;bottom:-1px"><i class="fa-solid fa-note-sticky" aria-hidden="true"></i> Internal note</button>
		</div>
		<div id="gd-tab-reply" style="display:block;padding:20px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;background:#fff">
			<p style="font-size:12px;color:#6b7280;margin:0 0 10px">Visible to dealer. Sends email + bell + PM notification.</p>
			{{if count($stock_replies) > 0}}
			<div style="margin-bottom:10px">
				<label style="font-size:12px;font-weight:600;color:#475569;margin-bottom:4px;display:block">Insert stock reply:</label>
				<select id="gd-stock-reply-picker" onchange="if(this.value){var b=this.options[this.selectedIndex].getAttribute('data-body');try{var eds=document.querySelectorAll('[id^=editor_support_admin_reply_]');if(eds.length){var el=eds[0];if(el.ckeditorInstance){el.ckeditorInstance.model.change(function(w){var r=el.ckeditorInstance.model.document.getRoot();w.appendElement('paragraph',r);var vf=el.ckeditorInstance.data.processor.toView(b);var mf=el.ckeditorInstance.data.toModel(vf);w.insert(mf,r,'end');})}else if(typeof CKEDITOR!=='undefined'){for(var k in CKEDITOR.instances){if(k.indexOf('editor_support_admin_reply_')===0){CKEDITOR.instances[k].insertHtml(b);break}}}else{var ta=document.querySelector('textarea[name=gddealer_support_admin_reply]');if(ta)ta.value+=b}}}catch(e){var ta2=document.querySelector('textarea[name=gddealer_support_admin_reply]');if(ta2)ta2.value+=b}this.selectedIndex=0}" style="padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em;width:100%">
					<option value="">-- Select a stock reply --</option>
					{{foreach $stock_replies as $sr}}
					<option value="{$sr['id']}" data-body="{expression="htmlspecialchars($sr['body'], ENT_QUOTES)"}">{$sr['title']}</option>
					{{endforeach}}
				</select>
			</div>
			{{endif}}
			<form method="post" action="{$reply_url}">
				<div style="margin-bottom:12px">{$reply_editor_html|raw}</div>
				<button type="submit" class="ipsButton ipsButton--primary ipsButton--small">Send Reply</button>
			</form>
		</div>
		<div id="gd-tab-note" style="display:none;padding:20px;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;background:#fffbeb">
			<p style="font-size:12px;color:#854d0e;margin:0 0 10px"><i class="fa-solid fa-lock" aria-hidden="true"></i> Internal only — dealer never sees this. No notifications sent.</p>
			<form method="post" action="{$add_note_url}">
				<div style="margin-bottom:12px">{$note_editor_html|raw}</div>
				<button type="submit" class="ipsButton ipsButton--small" style="background:#854d0e;color:#fff;border-color:#854d0e">Save Note</button>
			</form>
		</div>
	</div>
</div>
<aside style="flex:0 0 260px;min-width:240px">
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:16px">
		<h4 style="margin:0;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #f0f0f0;font-size:0.82em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569">Details</h4>
		<div style="padding:12px 14px;font-size:0.88em">
			<div style="margin-bottom:10px"><span style="color:#6b7280;display:block;font-size:0.85em;margin-bottom:2px">Submitter</span><strong>{$ticket['submitter_name']}</strong>{{if $ticket['submitter_email']}} <span style="color:#6b7280;font-size:0.85em">({$ticket['submitter_email']})</span>{{endif}}</div>
			<div style="margin-bottom:10px"><span style="color:#6b7280;display:block;font-size:0.85em;margin-bottom:2px">Dealer</span><strong>{$ticket['dealer_name']}</strong>{{if $ticket['dealer_tier']}} <span style="font-size:0.8em;color:#6b7280">({$ticket['dealer_tier']})</span>{{endif}}</div>
			<div style="margin-bottom:10px"><span style="color:#6b7280;display:block;font-size:0.85em;margin-bottom:2px">Department</span>{$ticket['department_name']}</div>
			<div style="margin-bottom:10px"><span style="color:#6b7280;display:block;font-size:0.85em;margin-bottom:2px">Assignee</span>{{if $ticket['assignee_name']}}{$ticket['assignee_name']}{{else}}<span style="color:#d1d5db">Unassigned</span>{{endif}}</div>
		</div>
	</div>
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:16px">
		<h4 style="margin:0;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #f0f0f0;font-size:0.82em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569">Actions</h4>
		<div style="padding:12px 14px">
			<form method="post" action="{$update_status_url}" style="margin-bottom:10px">
				<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Status</label>
				<select name="status" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em;margin-bottom:6px">
					<option value="open" {expression="$ticket['status'] === 'open' ? 'selected' : ''"}>Open</option>
					<option value="pending_staff" {expression="$ticket['status'] === 'pending_staff' ? 'selected' : ''"}>Awaiting Staff</option>
					<option value="pending_customer" {expression="$ticket['status'] === 'pending_customer' ? 'selected' : ''"}>Awaiting Customer</option>
					<option value="resolved" {expression="$ticket['status'] === 'resolved' ? 'selected' : ''"}>Resolved</option>
					<option value="closed" {expression="$ticket['status'] === 'closed' ? 'selected' : ''"}>Closed</option>
				</select>
				<button type="submit" class="ipsButton ipsButton--inherit ipsButton--verySmall" style="width:100%">Update Status</button>
			</form>
			<form method="post" action="{$update_priority_url}" style="margin-bottom:10px">
				<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Priority</label>
				<select name="priority" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em;margin-bottom:6px">
					<option value="low" {expression="$ticket['priority'] === 'low' ? 'selected' : ''"}>Low</option>
					<option value="normal" {expression="$ticket['priority'] === 'normal' ? 'selected' : ''"}>Normal</option>
					<option value="high" {expression="$ticket['priority'] === 'high' ? 'selected' : ''"}>High</option>
					<option value="urgent" {expression="$ticket['priority'] === 'urgent' ? 'selected' : ''"}>Urgent</option>
				</select>
				<button type="submit" class="ipsButton ipsButton--inherit ipsButton--verySmall" style="width:100%">Update Priority</button>
			</form>
			<form method="post" action="{$assign_url}" style="margin-bottom:10px">
				<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Assign to (member ID)</label>
				<input type="number" name="assignee" value="{$ticket['assignee_id']}" min="0" style="width:100%;padding:5px 8px;border:1px solid #d1d5db;border-radius:4px;font-size:0.85em;margin-bottom:6px;box-sizing:border-box">
				<button type="submit" class="ipsButton ipsButton--inherit ipsButton--verySmall" style="width:100%">Update Assignee</button>
			</form>
			<hr style="border:none;border-top:1px solid #e0e0e0;margin:12px 0">
			<a href="{$delete_url}" class="ipsButton ipsButton--negative ipsButton--verySmall" style="width:100%;text-align:center" onclick="return confirm('Delete this ticket and all replies? This cannot be undone.')">Delete Ticket</a>
		</div>
	</div>
	{{if count($stock_actions) > 0}}
	<div style="border:1px solid var(--i-border-color,#e0e0e0);border-radius:8px;margin-bottom:16px">
		<h4 style="margin:0;padding:10px 14px;background:#f8fafc;border-bottom:1px solid #f0f0f0;font-size:0.82em;font-weight:700;text-transform:uppercase;letter-spacing:0.05em;color:#475569">Quick Actions</h4>
		<div style="padding:12px 14px;display:flex;flex-direction:column;gap:6px">
			{{foreach $stock_actions as $sa}}
			<a href="{$sa['url']}" class="ipsButton ipsButton--inherit ipsButton--verySmall" style="width:100%;text-align:center" onclick="return confirm('Apply action: {$sa['title']}?')">{$sa['title']}</a>
			{{endforeach}}
		</div>
	</div>
	{{endif}}
</aside>
</div>
{{if count($events) > 0}}
<div style="margin-top:24px;border-top:1px solid #e5e7eb;padding-top:16px">
	<div style="font-size:11px;color:#6b7280;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:12px">Ticket history</div>
	{{foreach $events as $e}}
	<div style="display:flex;gap:12px;margin-bottom:10px;font-size:13px;color:#374151;align-items:flex-start">
		<span style="color:#9ca3af;font-size:12px;flex-shrink:0;width:160px">{$e['when']}</span>
		<div style="flex:1">
			<strong>{$e['actor_name']}</strong> {$e['verb']}
			{{if $e['note']}}
			<div style="font-size:12px;color:#4b5563;margin-top:4px;padding:8px 12px;background:#f9fafb;border-radius:6px;border-left:2px solid #d1d5db">{$e['note']}</div>
			{{endif}}
		</div>
	</div>
	{{endforeach}}
</div>
{{endif}}
</div>
</div>
TEMPLATE_EOT;

try {
    try {
        \IPS\Db::i()->delete( 'core_theme_templates', [
            'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id=?',
            'gddealer', 'admin', 'dealers', 'supportTicketView', 0
        ] );
    } catch ( \Throwable ) {}

    \IPS\Db::i()->replace( 'core_theme_templates', [
        'template_set_id'   => 1,
        'template_app'      => 'gddealer',
        'template_location' => 'admin',
        'template_group'    => 'dealers',
        'template_name'     => 'supportTicketView',
        'template_data'     => '$ticket, $ticket_body, $ticket_attachments, $replies, $reply_editor_html, $reply_url, $update_status_url, $update_priority_url, $assign_url, $delete_url, $back_url, $events, $note_editor_html, $add_note_url, $stock_replies, $stock_actions',
        'template_content'  => $supportTicketViewTpl,
        'template_updated'  => time(),
        'template_version'  => '1.0.213',
    ] );
}
catch ( \Throwable $e ) {
    try { \IPS\Log::log( 'templates_10213_supportTicketView failed: ' . $e->getMessage(), 'gddealer_canonical_templates' ); }
    catch ( \Throwable ) {}
}
