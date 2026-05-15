<?php
namespace IPS\gddealer\setup\upg_10202;
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
		$seeds = [
			[ 'name' => 'supportTickets', 'location' => 'admin', 'data' => '$rows, $status_filter, $priority_filter, $department_filter, $counts, $status_options, $priority_options, $department_options, $departments', 'content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">
<h2 style="margin:0 0 16px;font-size:1.4em;font-weight:700">Support Tickets</h2>
<div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px;padding-bottom:16px;border-bottom:1px solid var(--i-border-color,#e0e0e0)">
	<div>
		<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Status</label>
		<select onchange="window.location.href=this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:0.85em;background:#fff">
			<option value="{$status_options['active']}" {expression="$status_filter === 'active' ? 'selected' : ''"}>Active ({expression="number_format($counts['active'])"})</option>
			<option value="{$status_options['open']}" {expression="$status_filter === 'open' ? 'selected' : ''"}>Open ({expression="number_format($counts['open'])"})</option>
			<option value="{$status_options['pending_staff']}" {expression="$status_filter === 'pending_staff' ? 'selected' : ''"}>Awaiting Staff ({expression="number_format($counts['pending_staff'])"})</option>
			<option value="{$status_options['pending_customer']}" {expression="$status_filter === 'pending_customer' ? 'selected' : ''"}>Awaiting Customer ({expression="number_format($counts['pending_customer'])"})</option>
			<option value="{$status_options['resolved']}" {expression="$status_filter === 'resolved' ? 'selected' : ''"}>Resolved ({expression="number_format($counts['resolved'])"})</option>
			<option value="{$status_options['closed']}" {expression="$status_filter === 'closed' ? 'selected' : ''"}>Closed ({expression="number_format($counts['closed'])"})</option>
			<option value="{$status_options['all']}" {expression="$status_filter === 'all' ? 'selected' : ''"}>All ({expression="number_format($counts['all'])"})</option>
		</select>
	</div>
	<div>
		<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Priority</label>
		<select onchange="window.location.href=this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:0.85em;background:#fff">
			<option value="{$priority_options['all']}" {expression="$priority_filter === 'all' ? 'selected' : ''"}>All priorities</option>
			<option value="{$priority_options['urgent']}" {expression="$priority_filter === 'urgent' ? 'selected' : ''"}>Urgent</option>
			<option value="{$priority_options['high']}" {expression="$priority_filter === 'high' ? 'selected' : ''"}>High</option>
			<option value="{$priority_options['normal']}" {expression="$priority_filter === 'normal' ? 'selected' : ''"}>Normal</option>
			<option value="{$priority_options['low']}" {expression="$priority_filter === 'low' ? 'selected' : ''"}>Low</option>
		</select>
	</div>
	<div>
		<label style="font-size:0.8em;font-weight:600;color:#475569;display:block;margin-bottom:4px">Department</label>
		<select onchange="window.location.href=this.value" style="padding:6px 10px;border:1px solid #d1d5db;border-radius:5px;font-size:0.85em;background:#fff">
			{{foreach $departments as $did => $dname}}
			<option value="{$department_options[$did]}" {expression="$department_filter === $did ? 'selected' : ''"}>{{if $did === 0}}All departments{{else}}{$dname}{{endif}}</option>
			{{endforeach}}
		</select>
	</div>
</div>
{{if count($rows) > 0}}
<table class="ipsTable ipsTable--responsive" style="width:100%">
	<thead>
		<tr style="background:#f8fafc">
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Ticket</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Dealer</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Dept</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Priority</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Status</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Assignee</th>
			<th style="padding:10px 12px;font-size:0.78em;font-weight:700;color:#475569;text-transform:uppercase">Updated</th>
		</tr>
	</thead>
	<tbody>
		{{foreach $rows as $r}}
		<tr style="border-bottom:1px solid #f0f0f0;{{if $r['needs_attention']}}background:#fffbeb;{{endif}}{{if $r['is_enterprise']}}border-left:3px solid #d97706;{{endif}}">
			<td style="padding:10px 12px">
				{{if $r['needs_attention']}}<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;margin-right:6px;vertical-align:middle"></span>{{endif}}
				<a href="{$r['view_url']}" style="font-weight:600;color:#1d4ed8;text-decoration:none">{$r['subject']}</a>
				<div style="font-size:0.78em;color:#6b7280;margin-top:2px">#{$r['id']} &middot; {$r['submitter_name']}</div>
			</td>
			<td style="padding:10px 12px;font-size:0.85em">
				{$r['dealer_name']}
				{{if $r['is_enterprise']}}<span style="background:#fef3c7;color:#92400e;padding:1px 6px;border-radius:10px;font-size:0.75em;font-weight:600;margin-left:4px">Enterprise</span>{{endif}}
			</td>
			<td style="padding:10px 12px;font-size:0.85em;color:#6b7280">{$r['department_name']}</td>
			<td style="padding:10px 12px"><span style="background:{$r['priority_bg']};color:{$r['priority_color']};padding:2px 8px;border-radius:12px;font-size:0.78em;font-weight:600">{$r['priority_label']}</span></td>
			<td style="padding:10px 12px"><span style="background:{$r['status_bg']};color:{$r['status_color']};padding:2px 8px;border-radius:12px;font-size:0.78em;font-weight:600;white-space:nowrap">{$r['status_label']}</span></td>
			<td style="padding:10px 12px;font-size:0.85em;color:#6b7280">{{if $r['assignee_name']}}{$r['assignee_name']}{{else}}<span style="color:#d1d5db">&mdash;</span>{{endif}}</td>
			<td style="padding:10px 12px;font-size:0.8em;color:#6b7280;white-space:nowrap">
				{$r['updated_at_short']}
				{{if $r['last_reply_role']}}
				<span style="margin-left:4px">{{if $r['last_reply_role'] === 'admin'}}<i class="fa-solid fa-headset" title="Staff replied" style="color:#2563eb"></i>{{else}}<i class="fa-solid fa-store" title="Dealer replied" style="color:#6b7280"></i>{{endif}}</span>
				{{endif}}
			</td>
		</tr>
		{{endforeach}}
	</tbody>
</table>
{{else}}
<div style="text-align:center;padding:40px;color:#6b7280">
	<p>No tickets match the current filters.</p>
</div>
{{endif}}
</div>
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportTicketView', 'location' => 'admin', 'data' => '$ticket, $ticket_body, $ticket_attachments, $replies, $reply_editor_html, $reply_url, $update_status_url, $update_priority_url, $assign_url, $delete_url, $back_url, $events, $note_editor_html, $add_note_url, $stock_replies, $stock_actions', 'content' => <<<'TEMPLATE_EOT'
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
TEMPLATE_EOT ],
			[ 'name' => 'supportList', 'location' => 'front', 'data' => '$tickets, $subNav', 'content' => <<<'TEMPLATE_EOT'
<div>
<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:20px;padding-bottom:14px;border-bottom:0.5px solid #e5e7eb;flex-wrap:wrap">
	<div class="gdSubNav" style="display:flex;gap:4px;flex-wrap:wrap">
		<a href="{$subNav['open_url']}" style="padding:8px 14px;font-size:13px;font-weight:500;text-decoration:none;border-radius:8px;{{if $subNav['active'] === 'open'}}background:#1e3a5f;color:#fff{{else}}color:#475569{{endif}}">
			Open tickets
			{{if $subNav['open_count'] > 0}}
			<span style="display:inline-block;margin-left:6px;padding:1px 7px;{{if $subNav['active'] === 'open'}}background:rgba(255,255,255,0.18);color:#fff{{else}}background:#f1f5f9;color:#475569{{endif}};border-radius:10px;font-size:11px;font-weight:500">{$subNav['open_count']}</span>
			{{endif}}
		</a>
		<a href="{$subNav['closed_url']}" style="padding:8px 14px;font-size:13px;font-weight:500;text-decoration:none;border-radius:8px;{{if $subNav['active'] === 'closed'}}background:#1e3a5f;color:#fff{{else}}color:#475569{{endif}}">
			Closed
			{{if $subNav['closed_count'] > 0}}
			<span style="display:inline-block;margin-left:6px;padding:1px 7px;{{if $subNav['active'] === 'closed'}}background:rgba(255,255,255,0.18);color:#fff{{else}}background:#f1f5f9;color:#475569{{endif}};border-radius:10px;font-size:11px;font-weight:500">{$subNav['closed_count']}</span>
			{{endif}}
		</a>
		<a href="{$subNav['all_url']}" style="padding:8px 14px;font-size:13px;font-weight:500;text-decoration:none;border-radius:8px;{{if $subNav['active'] === 'all'}}background:#1e3a5f;color:#fff{{else}}color:#475569{{endif}}">
			All tickets
		</a>
	</div>
	<a href="{$subNav['new_url']}" style="padding:9px 18px;background:#16a34a;color:#fff;font-size:13px;font-weight:500;border-radius:8px;text-decoration:none;white-space:nowrap">
		+ New ticket
	</a>
</div>
{{if count($tickets) === 0}}
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:60px 40px;text-align:center">
	<div style="font-size:15px;color:#111827;font-weight:500;margin-bottom:8px">
		{{if $subNav['active'] === 'open'}}No open tickets{{elseif $subNav['active'] === 'closed'}}No closed tickets{{else}}No tickets yet{{endif}}
	</div>
	<div style="font-size:13px;color:#64748b;margin-bottom:20px">Need help? Open a ticket and we'll get back to you.</div>
	<a href="{$subNav['new_url']}" style="display:inline-block;padding:10px 20px;background:#16a34a;color:#fff;font-size:13px;font-weight:500;border-radius:8px;text-decoration:none">Open a new ticket</a>
</div>
{{else}}
<div class="gdSupportList__grid" style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;overflow:hidden">
	<div class="gdSupportList__header" style="display:grid;grid-template-columns:52px 1fr 110px 110px 140px;gap:14px;align-items:center;padding:12px 18px;background:#1e3a5f;color:#fff">
		<div></div>
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Subject</div>
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Status</div>
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Priority</div>
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Updated</div>
	</div>
	{{foreach $tickets as $t}}
	<a href="{$t['view_url']}" class="gdSupportList__row" style="display:grid;grid-template-columns:52px 1fr 110px 110px 140px;gap:14px;align-items:center;padding:14px 18px;text-decoration:none;color:inherit;border-bottom:0.5px solid #f1f5f9;{{if $t['needs_attention']}}border-left:3px solid #f59e0b;padding-left:15px{{endif}}">
		<div class="gdSupportList__iconCell" style="width:36px;height:36px;background:{$t['icon_bg']};border-radius:10px;display:flex;align-items:center;justify-content:center;color:{$t['icon_color']};font-weight:600;font-size:15px">{$t['icon_glyph']}</div>
		<div class="gdSupportList__subject" style="min-width:0">
			<div style="font-size:14px;font-weight:500;color:#111827;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{$t['subject']}</div>
			<div style="font-size:12px;color:#94a3b8;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{if $t['department_name']}}{$t['department_name']} &middot; {{endif}}#{$t['id']}</div>
		</div>
		<div class="gdSupportList__meta"><span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:{$t['status_bg']};color:{$t['status_color']};white-space:nowrap">{$t['status_label']}</span></div>
		<div class="gdSupportList__meta">{{if $t['priority'] !== 'normal'}}<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:{$t['priority_bg']};color:{$t['priority_color']};white-space:nowrap">{$t['priority_label']}</span>{{else}}<span style="font-size:12px;color:#94a3b8">Normal</span>{{endif}}</div>
		<div class="gdSupportList__meta" style="font-size:12px;color:#64748b">
			<div>{$t['updated_at_relative']}</div>
			{{if $t['last_reply_role'] === 'admin'}}
			<div style="font-size:11px;color:#1e40af;font-weight:500;margin-top:2px">Staff replied</div>
			{{elseif $t['last_reply_role'] === 'dealer'}}
			<div style="font-size:11px;color:#94a3b8;margin-top:2px">You replied</div>
			{{endif}}
		</div>
	</a>
	{{endforeach}}
</div>
{{endif}}
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportNew', 'location' => 'front', 'data' => '$departments, $canSetUrgent, $bodyEditorHtml, $csrfKey, $submitUrl, $cancelUrl', 'content' => <<<'TEMPLATE_EOT'
<div>
<div style="margin-bottom:14px">
	<a href="{$cancelUrl}" style="font-size:13px;color:#64748b;text-decoration:none">&larr; All tickets</a>
</div>
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:28px;max-width:780px">
	<div style="margin-bottom:24px;padding-bottom:18px;border-bottom:0.5px solid #f1f5f9">
		<h1 style="margin:0 0 6px;font-size:20px;font-weight:500;color:#111827">Open a new ticket</h1>
		<p style="margin:0;font-size:13px;color:#64748b">Tell us what's going on and we'll respond as soon as we can.</p>
	</div>
	<form method="post" action="{$submitUrl}">
		<input type="hidden" name="csrfKey" value="{$csrfKey}">
		<div style="margin-bottom:20px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Subject</label>
			<input type="text" name="support_subject" required maxlength="160" placeholder="Brief summary of your question or issue" style="width:100%;padding:10px 12px;font-size:14px;border:0.5px solid #e5e7eb;border-radius:8px;background:#fff;color:#111827;box-sizing:border-box">
		</div>
		<div class="gdFormGrid2" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:20px">
			<div>
				<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Department</label>
				<select name="support_department" style="width:100%;padding:10px 12px;font-size:14px;border:0.5px solid #e5e7eb;border-radius:8px;background:#fff;color:#111827;box-sizing:border-box">
					{{foreach $departments as $dept}}
					<option value="{$dept['id']}">{$dept['name']}</option>
					{{endforeach}}
				</select>
			</div>
			<div>
				<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Priority</label>
				<select name="support_priority" style="width:100%;padding:10px 12px;font-size:14px;border:0.5px solid #e5e7eb;border-radius:8px;background:#fff;color:#111827;box-sizing:border-box">
					<option value="low">Low</option>
					<option value="normal" selected>Normal</option>
					<option value="high">High</option>
					{{if $canSetUrgent}}<option value="urgent">Urgent</option>{{endif}}
				</select>
			</div>
		</div>
		<div style="margin-bottom:24px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Message</label>
			<div>{$bodyEditorHtml|raw}</div>
			<p style="margin:6px 0 0;font-size:12px;color:#94a3b8">Include any relevant screenshots, links, or error messages.</p>
		</div>
		<div style="display:flex;gap:10px;align-items:center;padding-top:18px;border-top:0.5px solid #f1f5f9">
			<button type="submit" style="padding:10px 22px;background:#16a34a;color:#fff;font-size:14px;font-weight:500;border:none;border-radius:8px;cursor:pointer">Submit ticket</button>
			<a href="{$cancelUrl}" style="padding:10px 22px;background:transparent;color:#64748b;font-size:14px;font-weight:500;border:0.5px solid #e5e7eb;border-radius:8px;text-decoration:none">Cancel</a>
		</div>
	</form>
</div>
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportView', 'location' => 'front', 'data' => '$ticket, $ticketBody, $ticketAttachments, $replies, $replyEditorHtml, $csrfKey, $replyUrl, $closeUrl, $backUrl, $canReply, $canClose, $events, $newTicketUrl', 'content' => <<<'TEMPLATE_EOT'
<div>
<div style="margin-bottom:14px">
	<a href="{$backUrl}" style="font-size:13px;color:#64748b;text-decoration:none">&larr; All tickets</a>
</div>
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:12px">
	<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:14px">
		<h1 style="margin:0;font-size:20px;font-weight:500;color:#111827;line-height:1.4">{$ticket['subject']}</h1>
		<div style="display:flex;gap:6px;flex-shrink:0;flex-wrap:wrap;justify-content:flex-end">
			<span style="padding:4px 12px;border-radius:12px;font-size:11px;font-weight:500;background:{$ticket['status_bg']};color:{$ticket['status_color']};white-space:nowrap">{$ticket['status_label']}</span>
			{{if $ticket['priority'] !== 'normal'}}
			<span style="padding:4px 12px;border-radius:12px;font-size:11px;font-weight:500;background:{$ticket['priority_bg']};color:{$ticket['priority_color']};white-space:nowrap">{$ticket['priority_label']}</span>
			{{endif}}
		</div>
	</div>
	<div style="display:flex;gap:14px;font-size:12px;color:#64748b;flex-wrap:wrap">
		<span>Ticket #{$ticket['id']}</span>
		{{if $ticket['department_name']}}<span>&middot;</span><span>{$ticket['department_name']}</span>{{endif}}
		<span>&middot;</span>
		<span>Opened {$ticket['created_at']}</span>
	</div>
</div>
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:22px 24px;margin-bottom:20px;border-left:3px solid #16a34a">
	<div style="display:flex;align-items:center;gap:10px;margin-bottom:12px">
		<span style="padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:#dcfce7;color:#166534">You</span>
		<span style="font-size:12px;color:#94a3b8">{$ticket['created_at']}</span>
	</div>
	<div style="font-size:14px;color:#374151;line-height:1.65">{$ticketBody|raw}</div>
	{{if count($ticketAttachments) > 0}}
	<div style="margin-top:16px;padding-top:12px;border-top:0.5px solid #f1f5f9">
		<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;font-weight:500;margin-bottom:8px">Attachments</div>
		<div style="display:flex;flex-wrap:wrap;gap:10px">
			{{foreach $ticketAttachments as $att}}
			<a href="{$att['url']}" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:#f8fafc;border:0.5px solid #e5e7eb;border-radius:8px;font-size:13px;color:#1e3a5f;text-decoration:none">{$att['filename']}</a>
			{{endforeach}}
		</div>
	</div>
	{{endif}}
</div>
{{if count($replies) > 0}}
<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;font-weight:500;margin:0 0 10px 2px">
	{expression="count($replies)"} {expression="count($replies) === 1 ? 'reply' : 'replies'"}
</div>
<div style="display:flex;flex-direction:column;gap:10px;margin-bottom:24px">
	{{foreach $replies as $r}}
	<div class="gdReplyCard" style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:20px 24px;border-left:3px solid {$r['role_border']}">
		<div style="display:flex;align-items:center;gap:10px;margin-bottom:10px">
			<span style="padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:{$r['role_bg']};color:{$r['role_color']}">{$r['role_label']}</span>
			<strong style="font-size:13px;color:#111827;font-weight:500">{$r['author_name']}</strong>
			<span style="font-size:12px;color:#94a3b8">{$r['created_at']}</span>
		</div>
		<div style="font-size:14px;color:#374151;line-height:1.65">{$r['body']|raw}</div>
	</div>
	{{endforeach}}
</div>
{{endif}}
{{if $canReply}}
<div style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:24px;margin-bottom:20px">
	<h3 style="margin:0 0 14px;font-size:15px;font-weight:500;color:#111827">Your reply</h3>
	<form method="post" action="{$replyUrl}">
		<input type="hidden" name="csrfKey" value="{$csrfKey}">
		<div style="margin-bottom:14px">{$replyEditorHtml|raw}</div>
		<div style="display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap">
			<button type="submit" style="padding:10px 22px;background:#16a34a;color:#fff;font-size:14px;font-weight:500;border:none;border-radius:8px;cursor:pointer">Post reply</button>
			{{if $canClose}}
			<a href="{$closeUrl}" style="font-size:13px;color:#64748b;text-decoration:none" onclick="return confirm('Close this ticket? You can always open a new one if you need more help.');">Close this ticket</a>
			{{endif}}
		</div>
	</form>
</div>
{{else}}
<div style="background:#f8fafc;border:0.5px solid #e5e7eb;border-radius:12px;padding:32px 24px;text-align:center;margin-bottom:20px">
	<div style="font-size:14px;color:#64748b">This ticket is closed. <a href="{$newTicketUrl}" style="color:#16a34a;font-weight:500;text-decoration:none">Open a new ticket</a> to continue the conversation.</div>
</div>
{{endif}}
{{if count($events) > 0}}
<div class="gdTicketHistory" style="background:#fff;border:0.5px solid #e5e7eb;border-radius:12px;padding:20px 24px">
	<div style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#64748b;font-weight:500;margin-bottom:14px">Ticket history</div>
	{{foreach $events as $e}}
	<div style="display:flex;gap:16px;margin-bottom:8px;font-size:13px;color:#374151;align-items:baseline">
		<span style="color:#94a3b8;font-size:12px;flex-shrink:0;width:180px">{$e['when']}</span>
		<div style="flex:1;min-width:0"><strong style="font-weight:500">{$e['actor_name']}</strong> {$e['verb']}
			{{if $e['note']}}
			<div style="font-size:12px;color:#4b5563;margin-top:4px;padding:8px 12px;background:#f9fafb;border-radius:6px;border-left:2px solid #d1d5db">{$e['note']}</div>
			{{endif}}
		</div>
	</div>
	{{endforeach}}
</div>
{{endif}}
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportDepartments', 'location' => 'admin', 'data' => '$departments, $addUrl', 'content' => <<<'TEMPLATE_EOT'
<div class="ipsPad">
<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
	<h1 class="ipsType_pageTitle" style="margin:0">Support Departments</h1>
	<a href="{$addUrl}" class="ipsButton ipsButton--primary"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Department</a>
</div>
{{if count($departments) === 0}}
<div style="padding:40px;text-align:center;background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;color:#6b7280">
	<p style="margin:0 0 12px;font-size:14px">No support departments configured.</p>
	<a href="{$addUrl}" class="ipsButton ipsButton--primary ipsButton--small">Create the first department</a>
</div>
{{else}}
<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden">
	<table style="width:100%;border-collapse:collapse">
		<thead>
			<tr style="background:#f9fafb;border-bottom:1px solid #e5e7eb">
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500;width:40px">#</th>
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Department</th>
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Visibility</th>
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Tickets</th>
				<th style="text-align:left;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Status</th>
				<th style="text-align:right;padding:12px 16px;font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:#6b7280;font-weight:500">Actions</th>
			</tr>
		</thead>
		<tbody>
			{{foreach $departments as $d}}
			<tr style="border-bottom:1px solid #f3f4f6">
				<td style="padding:14px 16px;color:#9ca3af;font-size:13px">{$d['position']}</td>
				<td style="padding:14px 16px">
					<div style="font-size:14px;font-weight:500;color:#111827">{$d['name']}</div>
					{{if $d['description']}}<div style="font-size:12px;color:#6b7280;margin-top:2px">{$d['description']}</div>{{endif}}
					{{if $d['email']}}<div style="font-size:11px;color:#9ca3af;margin-top:2px"><i class="fa-solid fa-envelope" aria-hidden="true"></i> {$d['email']}</div>{{endif}}
				</td>
				<td style="padding:14px 16px">
					<span style="display:inline-block;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:{$d['visibility_bg']};color:{$d['visibility_color']}">{$d['visibility_label']}</span>
				</td>
				<td style="padding:14px 16px;font-size:13px;color:#374151">{$d['ticket_count']}</td>
				<td style="padding:14px 16px">
					<a href="{$d['toggle_url']}" style="font-size:12px;color:{{if $d['enabled']}}#16a34a{{else}}#9ca3af{{endif}};text-decoration:none">
						<i class="fa-solid {{if $d['enabled']}}fa-circle-check{{else}}fa-circle-xmark{{endif}}" aria-hidden="true"></i>
						{{if $d['enabled']}}Enabled{{else}}Disabled{{endif}}
					</a>
				</td>
				<td style="padding:14px 16px;text-align:right;white-space:nowrap">
					<a href="{$d['move_up_url']}" style="color:#6b7280;text-decoration:none;padding:4px 6px" title="Move up"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i></a>
					<a href="{$d['move_down_url']}" style="color:#6b7280;text-decoration:none;padding:4px 6px" title="Move down"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i></a>
					<a href="{$d['edit_url']}" style="color:#2563eb;text-decoration:none;padding:4px 10px;font-size:13px">Edit</a>
					<a href="{$d['delete_url']}" style="color:#dc2626;text-decoration:none;padding:4px 10px;font-size:13px" onclick="return confirm('Delete this department? Only works if no tickets reference it.');">Delete</a>
				</td>
			</tr>
			{{endforeach}}
		</tbody>
	</table>
</div>
{{endif}}
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportDepartmentForm', 'location' => 'admin', 'data' => '$formData, $isEdit, $submitUrl, $backUrl, $csrfKey', 'content' => <<<'TEMPLATE_EOT'
<div class="ipsPad">
<div style="margin-bottom:16px">
	<a href="{$backUrl}" style="font-size:13px;color:#6b7280;text-decoration:none">&larr; Back to departments</a>
</div>
<h1 class="ipsType_pageTitle" style="margin:0 0 20px">{{if $isEdit}}Edit Department{{else}}Add Department{{endif}}</h1>
<form method="post" action="{$submitUrl}" style="max-width:640px">
	<input type="hidden" name="csrfKey" value="{$csrfKey}">
	<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px">
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Name <span style="color:#dc2626">*</span></label>
			<input type="text" name="name" value="{$formData['name']}" required class="ipsInput ipsInput--text" style="width:100%" maxlength="128">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Short label shown to dealers in the department dropdown.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Description</label>
			<textarea name="description" rows="2" class="ipsInput ipsInput--text" style="width:100%;resize:vertical">{$formData['description']}</textarea>
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Optional helper text shown below the department name when dealers pick it.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Notification email</label>
			<input type="email" name="email" value="{$formData['email']}" class="ipsInput ipsInput--text" style="width:100%" maxlength="255">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Optional email that receives copies of new tickets in this department. Leave blank to use the default admin recipient list.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Visibility</label>
			<select name="visibility" class="ipsInput ipsInput--select" style="width:100%">
				<option value="public" {expression="$formData['visibility'] === 'public' ? 'selected' : ''"}>Public — all dealers regardless of tier</option>
				<option value="pro" {expression="$formData['visibility'] === 'pro' ? 'selected' : ''"}>Pro+ — Pro, Founding, and Enterprise dealers</option>
				<option value="enterprise" {expression="$formData['visibility'] === 'enterprise' ? 'selected' : ''"}>Enterprise only — Enterprise and Founding dealers</option>
			</select>
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Which dealer tiers can submit tickets to this department.</p>
		</div>
		<div style="margin-bottom:4px">
			<label style="display:inline-flex;align-items:center;gap:10px;font-size:13px;font-weight:500;color:#111827;cursor:pointer">
				<input type="checkbox" name="enabled" value="1" {expression="$formData['enabled'] ? 'checked' : ''"}>
				<span>Enabled</span>
			</label>
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0 26px">Disabled departments are hidden from the new-ticket form but existing tickets stay accessible.</p>
		</div>
	</div>
	<div style="margin-top:20px;display:flex;gap:10px">
		<button type="submit" class="ipsButton ipsButton--primary">{{if $isEdit}}Save Changes{{else}}Create Department{{endif}}</button>
		<a href="{$backUrl}" class="ipsButton ipsButton--light">Cancel</a>
	</div>
</form>
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportStockReplies', 'location' => 'admin', 'data' => '$rows, $addUrl', 'content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
	<h1 class="ipsType_pageTitle" style="margin:0">Stock Replies</h1>
	<a href="{$addUrl}" class="ipsButton ipsButton--primary ipsButton--small"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Stock Reply</a>
</div>
<p style="font-size:13px;color:#6b7280;margin:0 0 16px">Canned reply templates that staff can insert into ticket replies with one click.</p>
{{if count($rows) > 0}}
<table class="ipsTable ipsTable_zebra" style="width:100%">
	<thead>
		<tr>
			<th style="width:30px"></th>
			<th>Title</th>
			<th>Department</th>
			<th style="width:80px">Status</th>
			<th style="width:180px">Actions</th>
		</tr>
	</thead>
	<tbody>
		{{foreach $rows as $r}}
		<tr>
			<td style="text-align:center;color:#9ca3af">
				<a href="{$r['move_up_url']}" title="Move up" style="color:#6b7280;text-decoration:none">&uarr;</a>
				<a href="{$r['move_down_url']}" title="Move down" style="color:#6b7280;text-decoration:none">&darr;</a>
			</td>
			<td><strong>{$r['title']}</strong></td>
			<td style="font-size:0.9em;color:#6b7280">{$r['department_name']}</td>
			<td>
				{{if $r['enabled']}}
				<span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:12px;font-size:0.8em;font-weight:600">Enabled</span>
				{{else}}
				<span style="background:#f1f5f9;color:#64748b;padding:2px 10px;border-radius:12px;font-size:0.8em;font-weight:600">Disabled</span>
				{{endif}}
			</td>
			<td style="font-size:0.85em">
				<a href="{$r['edit_url']}" style="color:#2563eb;text-decoration:none;margin-right:8px">Edit</a>
				<a href="{$r['toggle_url']}" style="color:#6b7280;text-decoration:none;margin-right:8px">{{if $r['enabled']}}Disable{{else}}Enable{{endif}}</a>
				<a href="{$r['delete_url']}" style="color:#dc2626;text-decoration:none" onclick="return confirm('Delete this stock reply?')">Delete</a>
			</td>
		</tr>
		{{endforeach}}
	</tbody>
</table>
{{else}}
<div style="text-align:center;padding:40px 20px;color:#6b7280">
	<p>No stock replies yet. Create one to speed up ticket responses.</p>
</div>
{{endif}}
</div>
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportStockReplyForm', 'location' => 'admin', 'data' => '$formData, $isEdit, $editorHtml, $departments, $submitUrl, $backUrl, $csrfKey', 'content' => <<<'TEMPLATE_EOT'
<div class="ipsPad">
<div style="margin-bottom:16px">
	<a href="{$backUrl}" style="font-size:13px;color:#6b7280;text-decoration:none">&larr; Back to stock replies</a>
</div>
<h1 class="ipsType_pageTitle" style="margin:0 0 20px">{{if $isEdit}}Edit Stock Reply{{else}}Add Stock Reply{{endif}}</h1>
<form method="post" action="{$submitUrl}" style="max-width:720px">
	<input type="hidden" name="csrfKey" value="{$csrfKey}">
	<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px">
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Title <span style="color:#dc2626">*</span></label>
			<input type="text" name="title" value="{$formData['title']}" required class="ipsInput ipsInput--text" style="width:100%" maxlength="255">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Label shown in the stock reply picker on the ticket view.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Reply body</label>
			{$editorHtml|raw}
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">This content will be inserted into the reply editor when staff select this stock reply.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Department scope</label>
			<select name="department_id" class="ipsInput ipsInput--select" style="width:100%">
				{{foreach $departments as $dId => $dName}}
				<option value="{$dId}" {expression="(int)$formData['department_id'] === (int)$dId ? 'selected' : ''"}>
					{$dName}
				</option>
				{{endforeach}}
			</select>
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Global replies appear on all tickets. Department-scoped replies only appear on tickets in that department.</p>
		</div>
		<div style="margin-bottom:4px">
			<label style="display:inline-flex;align-items:center;gap:10px;font-size:13px;font-weight:500;color:#111827;cursor:pointer">
				<input type="checkbox" name="enabled" value="1" {expression="$formData['enabled'] ? 'checked' : ''"}>
				<span>Enabled</span>
			</label>
		</div>
	</div>
	<div style="margin-top:20px;display:flex;gap:10px">
		<button type="submit" class="ipsButton ipsButton--primary">{{if $isEdit}}Save Changes{{else}}Create Stock Reply{{endif}}</button>
		<a href="{$backUrl}" class="ipsButton ipsButton--light">Cancel</a>
	</div>
</form>
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportStockActions', 'location' => 'admin', 'data' => '$rows, $addUrl', 'content' => <<<'TEMPLATE_EOT'
<div class="ipsBox ipsPull">
<div class="ipsBox_body ipsPad">
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
	<h1 class="ipsType_pageTitle" style="margin:0">Stock Actions</h1>
	<a href="{$addUrl}" class="ipsButton ipsButton--primary ipsButton--small"><i class="fa-solid fa-plus" aria-hidden="true"></i> Add Stock Action</a>
</div>
<p style="font-size:13px;color:#6b7280;margin:0 0 16px">Multi-step ticket actions: auto-reply + change status/priority/assignee in one click.</p>
{{if count($rows) > 0}}
<table class="ipsTable ipsTable_zebra" style="width:100%">
	<thead>
		<tr>
			<th style="width:30px"></th>
			<th>Title</th>
			<th>Effects</th>
			<th>Department</th>
			<th style="width:80px">Status</th>
			<th style="width:180px">Actions</th>
		</tr>
	</thead>
	<tbody>
		{{foreach $rows as $r}}
		<tr>
			<td style="text-align:center;color:#9ca3af">
				<a href="{$r['move_up_url']}" title="Move up" style="color:#6b7280;text-decoration:none">&uarr;</a>
				<a href="{$r['move_down_url']}" title="Move down" style="color:#6b7280;text-decoration:none">&darr;</a>
			</td>
			<td><strong>{$r['title']}</strong></td>
			<td style="font-size:0.85em;color:#4b5563">{$r['effects']}</td>
			<td style="font-size:0.9em;color:#6b7280">{$r['department_name']}</td>
			<td>
				{{if $r['enabled']}}
				<span style="background:#dcfce7;color:#166534;padding:2px 10px;border-radius:12px;font-size:0.8em;font-weight:600">Enabled</span>
				{{else}}
				<span style="background:#f1f5f9;color:#64748b;padding:2px 10px;border-radius:12px;font-size:0.8em;font-weight:600">Disabled</span>
				{{endif}}
			</td>
			<td style="font-size:0.85em">
				<a href="{$r['edit_url']}" style="color:#2563eb;text-decoration:none;margin-right:8px">Edit</a>
				<a href="{$r['toggle_url']}" style="color:#6b7280;text-decoration:none;margin-right:8px">{{if $r['enabled']}}Disable{{else}}Enable{{endif}}</a>
				<a href="{$r['delete_url']}" style="color:#dc2626;text-decoration:none" onclick="return confirm('Delete this stock action?')">Delete</a>
			</td>
		</tr>
		{{endforeach}}
	</tbody>
</table>
{{else}}
<div style="text-align:center;padding:40px 20px;color:#6b7280">
	<p>No stock actions yet. Create one to automate common ticket workflows.</p>
</div>
{{endif}}
</div>
</div>
TEMPLATE_EOT ],
			[ 'name' => 'supportStockActionForm', 'location' => 'admin', 'data' => '$formData, $isEdit, $editorHtml, $departments, $adminMembers, $submitUrl, $backUrl, $csrfKey', 'content' => <<<'TEMPLATE_EOT'
<div class="ipsPad">
<div style="margin-bottom:16px">
	<a href="{$backUrl}" style="font-size:13px;color:#6b7280;text-decoration:none">&larr; Back to stock actions</a>
</div>
<h1 class="ipsType_pageTitle" style="margin:0 0 20px">{{if $isEdit}}Edit Stock Action{{else}}Add Stock Action{{endif}}</h1>
<form method="post" action="{$submitUrl}" style="max-width:720px">
	<input type="hidden" name="csrfKey" value="{$csrfKey}">
	<div style="background:#fff;border:1px solid #e5e7eb;border-radius:8px;padding:24px">
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Title <span style="color:#dc2626">*</span></label>
			<input type="text" name="title" value="{$formData['title']}" required class="ipsInput ipsInput--text" style="width:100%" maxlength="255">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Label shown in the stock action picker on the ticket view.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Auto-reply body (optional)</label>
			{$editorHtml|raw}
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">If provided, this reply will be posted to the ticket when the action runs. Dealer will be notified.</p>
		</div>
		<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:18px">
			<div>
				<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Set status to</label>
				<select name="new_status" class="ipsInput ipsInput--select" style="width:100%">
					<option value="" {expression="$formData['new_status'] === '' ? 'selected' : ''"}>No change</option>
					<option value="open" {expression="$formData['new_status'] === 'open' ? 'selected' : ''"}>Open</option>
					<option value="pending_staff" {expression="$formData['new_status'] === 'pending_staff' ? 'selected' : ''"}>Awaiting Staff</option>
					<option value="pending_customer" {expression="$formData['new_status'] === 'pending_customer' ? 'selected' : ''"}>Awaiting Customer</option>
					<option value="resolved" {expression="$formData['new_status'] === 'resolved' ? 'selected' : ''"}>Resolved</option>
					<option value="closed" {expression="$formData['new_status'] === 'closed' ? 'selected' : ''"}>Closed</option>
				</select>
			</div>
			<div>
				<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Set priority to</label>
				<select name="new_priority" class="ipsInput ipsInput--select" style="width:100%">
					<option value="" {expression="$formData['new_priority'] === '' ? 'selected' : ''"}>No change</option>
					<option value="low" {expression="$formData['new_priority'] === 'low' ? 'selected' : ''"}>Low</option>
					<option value="normal" {expression="$formData['new_priority'] === 'normal' ? 'selected' : ''"}>Normal</option>
					<option value="high" {expression="$formData['new_priority'] === 'high' ? 'selected' : ''"}>High</option>
					<option value="urgent" {expression="$formData['new_priority'] === 'urgent' ? 'selected' : ''"}>Urgent</option>
				</select>
			</div>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Assign to (member ID)</label>
			<input type="text" name="new_assignee" value="{$formData['new_assignee']}" class="ipsInput ipsInput--text" style="width:100%" placeholder="Leave blank for no change, 0 to unassign">
			<p style="font-size:12px;color:#6b7280;margin:4px 0 0">Enter a member ID or 0 to unassign. Leave blank to skip assignment changes.</p>
		</div>
		<div style="margin-bottom:18px">
			<label style="display:block;font-size:13px;font-weight:500;color:#111827;margin-bottom:6px">Department scope</label>
			<select name="department_id" class="ipsInput ipsInput--select" style="width:100%">
				{{foreach $departments as $dId => $dName}}
				<option value="{$dId}" {expression="(int)$formData['department_id'] === (int)$dId ? 'selected' : ''"}>
					{$dName}
				</option>
				{{endforeach}}
			</select>
		</div>
		<div style="margin-bottom:4px">
			<label style="display:inline-flex;align-items:center;gap:10px;font-size:13px;font-weight:500;color:#111827;cursor:pointer">
				<input type="checkbox" name="enabled" value="1" {expression="$formData['enabled'] ? 'checked' : ''"}>
				<span>Enabled</span>
			</label>
		</div>
	</div>
	<div style="margin-top:20px;display:flex;gap:10px">
		<button type="submit" class="ipsButton ipsButton--primary">{{if $isEdit}}Save Changes{{else}}Create Stock Action{{endif}}</button>
		<a href="{$backUrl}" class="ipsButton ipsButton--light">Cancel</a>
	</div>
</form>
</div>
TEMPLATE_EOT ],
			[ 'name' => 'fflVerifications', 'location' => 'admin', 'data' => '$data', 'content' => <<<'TEMPLATE_EOT'
<style>
.gddealerFflQueue { padding: 0 4px; }
.gddealerFflQueue__tabs { display: flex; gap: 4px; border-bottom: 1px solid #e5e7eb; margin-bottom: 16px; }
.gddealerFflQueue__tab { padding: 10px 14px; font-size: 14px; font-weight: 500; color: #475569; text-decoration: none; border-bottom: 2px solid transparent; }
.gddealerFflQueue__tab.is-active { color: #1e40af; border-bottom-color: #1e40af; }
.gddealerFflQueue__tab .count { display: inline-block; background: #e5e7eb; color: #475569; font-size: 11px; padding: 1px 7px; border-radius: 999px; margin-left: 6px; font-weight: 600; }
.gddealerFflQueue__tab.is-active .count { background: #dbeafe; color: #1e40af; }
.gddealerFflTable { width: 100%; border-collapse: collapse; }
.gddealerFflTable th { text-align: left; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.05em; color: #64748b; padding: 10px 12px; border-bottom: 2px solid #e5e7eb; }
.gddealerFflTable td { padding: 14px 12px; border-bottom: 1px solid #f1f5f9; vertical-align: middle; font-size: 14px; }
.gddealerFflTable__ffl { font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 13px; color: #0f172a; }
.gddealerFflTable__actions { display: flex; gap: 6px; justify-content: flex-end; }
.gddealerFflTable__btn { padding: 6px 12px; font-size: 13px; font-weight: 500; border-radius: 5px; text-decoration: none; border: 1px solid; cursor: pointer; }
.gddealerFflTable__btn--view { background: #f8fafc; border-color: #e5e7eb; color: #334155; }
.gddealerFflTable__btn--verify { background: #10b981; border-color: #059669; color: #fff; }
.gddealerFflTable__btn--reject { background: #fff; border-color: #ef4444; color: #ef4444; }
.gddealerFflTable__status { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; }
.gddealerFflTable__status--pending { background: #fef3c7; color: #92400e; }
.gddealerFflTable__status--verified { background: #d1fae5; color: #065f46; }
.gddealerFflTable__status--rejected { background: #fee2e2; color: #991b1b; }
.gddealerFflTable__empty { padding: 40px; text-align: center; color: #94a3b8; font-size: 14px; }
.gddealerFflTable__rejectionReason { font-size: 12px; color: #991b1b; font-style: italic; margin-top: 4px; }
</style>

<div class="gddealerFflQueue">
	<div class="gddealerFflQueue__tabs">
		<a href="{$data['filter_urls']['pending']}"  class="gddealerFflQueue__tab {expression="$data['filter'] === 'pending'  ? 'is-active' : ''"}">{lang="gddealer_acp_ffl_filter_pending"}<span class="count">{$data['counts']['pending']}</span></a>
		<a href="{$data['filter_urls']['verified']}" class="gddealerFflQueue__tab {expression="$data['filter'] === 'verified' ? 'is-active' : ''"}">{lang="gddealer_acp_ffl_filter_verified"}<span class="count">{$data['counts']['verified']}</span></a>
		<a href="{$data['filter_urls']['rejected']}" class="gddealerFflQueue__tab {expression="$data['filter'] === 'rejected' ? 'is-active' : ''"}">{lang="gddealer_acp_ffl_filter_rejected"}<span class="count">{$data['counts']['rejected']}</span></a>
		<a href="{$data['filter_urls']['all']}"      class="gddealerFflQueue__tab {expression="$data['filter'] === 'all'      ? 'is-active' : ''"}">{lang="gddealer_acp_ffl_filter_all"}<span class="count">{$data['counts']['all']}</span></a>
	</div>

	{{if empty( $data['rows'] )}}
	<div class="gddealerFflTable__empty">{lang="gddealer_acp_ffl_empty_pending"}</div>
	{{else}}
	<table class="gddealerFflTable">
		<thead>
			<tr>
				<th>Dealer</th>
				<th>FFL #</th>
				<th>Submitted</th>
				<th>License</th>
				<th>Status</th>
				<th style="text-align:right;">Actions</th>
			</tr>
		</thead>
		<tbody>
			{{foreach $data['rows'] as $r}}
			<tr>
				<td>
					<div style="font-weight:600;color:#0f172a;">{$r['dealer_name']}</div>
					<div style="font-size:12px;color:#64748b;">ID {$r['dealer_id']} · {$r['dealer_slug']}</div>
					{{if $r['status'] === 'rejected' && $r['ffl_rejection_reason']}}
					<div class="gddealerFflTable__rejectionReason">Last rejection: {$r['ffl_rejection_reason']} (attempt {$r['ffl_rejection_count']} of 3)</div>
					{{endif}}
				</td>
				<td class="gddealerFflTable__ffl">{$r['ffl_number']}</td>
				<td>{$r['ffl_submitted_label']}</td>
				<td>
					{{if $r['ffl_license_url']}}
					<a href="{$r['ffl_license_url']}" target="_blank" rel="nofollow noopener" class="gddealerFflTable__btn gddealerFflTable__btn--view">View PDF</a>
					{{else}}
					<span style="color:#94a3b8;font-size:12px;">No URL</span>
					{{endif}}
				</td>
				<td>
					{{if $r['status'] === 'pending'}}
					<span class="gddealerFflTable__status gddealerFflTable__status--pending">Pending</span>
					{{elseif $r['status'] === 'verified'}}
					<span class="gddealerFflTable__status gddealerFflTable__status--verified">Verified {$r['ffl_verified_label']}</span>
					{{elseif $r['status'] === 'rejected'}}
					<span class="gddealerFflTable__status gddealerFflTable__status--rejected">Rejected</span>
					{{endif}}
				</td>
				<td>
					<div class="gddealerFflTable__actions">
						{{if $r['status'] !== 'verified'}}
						<a href="{$r['verify_url']}" class="gddealerFflTable__btn gddealerFflTable__btn--verify" onclick="return confirm('Mark this FFL as verified?');">Verify</a>
						{{endif}}
						{{if $r['status'] !== 'rejected' || $r['ffl_rejection_count'] < 3}}
						<a href="{$r['reject_url']}" class="gddealerFflTable__btn gddealerFflTable__btn--reject">Reject</a>
						{{endif}}
					</div>
				</td>
			</tr>
			{{endforeach}}
		</tbody>
	</table>
	{{endif}}
</div>
TEMPLATE_EOT ],
			[ 'name' => 'fflRejectForm', 'location' => 'admin', 'data' => '$data', 'content' => <<<'TEMPLATE_EOT'
<style>
.gddealerFflReject { max-width: 560px; margin: 0 auto; padding: 24px; background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; }
.gddealerFflReject__title { margin: 0 0 4px; font-size: 18px; font-weight: 600; color: #0f172a; }
.gddealerFflReject__sub { margin: 0 0 20px; font-size: 13px; color: #64748b; }
.gddealerFflReject__reasonOption { display: block; padding: 12px 14px; margin-bottom: 8px; border: 1px solid #e5e7eb; border-radius: 6px; cursor: pointer; font-size: 14px; color: #334155; }
.gddealerFflReject__reasonOption:hover { background: #f8fafc; }
.gddealerFflReject__reasonOption input { margin-right: 10px; }
.gddealerFflReject__otherBox { margin-top: 8px; display: none; }
.gddealerFflReject__otherBox.is-visible { display: block; }
.gddealerFflReject__otherTextarea { width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 6px; font: inherit; resize: vertical; min-height: 80px; box-sizing: border-box; }
.gddealerFflReject__actions { margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end; }
.gddealerFflReject__btn { padding: 8px 16px; font-size: 14px; font-weight: 500; border-radius: 6px; cursor: pointer; text-decoration: none; }
.gddealerFflReject__btn--cancel { background: #fff; border: 1px solid #e5e7eb; color: #475569; }
.gddealerFflReject__btn--submit { background: #ef4444; border: 1px solid #dc2626; color: #fff; }
</style>

<div class="gddealerFflReject">
	<h2 class="gddealerFflReject__title">Reject FFL submission</h2>
	<p class="gddealerFflReject__sub">Dealer: <strong>{$data['dealer']['dealer_name']}</strong> · FFL # {$data['dealer']['ffl_number']}</p>

	<form method="post" action="{$data['post_url']}" id="gddealerFflRejectForm">
		<label class="gddealerFflReject__reasonOption"><input type="radio" name="reason_key" value="illegible" required> {lang="gddealer_ffl_rejection_illegible"}</label>
		<label class="gddealerFflReject__reasonOption"><input type="radio" name="reason_key" value="expired"> {lang="gddealer_ffl_rejection_expired"}</label>
		<label class="gddealerFflReject__reasonOption"><input type="radio" name="reason_key" value="mismatch"> {lang="gddealer_ffl_rejection_mismatch"}</label>
		<label class="gddealerFflReject__reasonOption"><input type="radio" name="reason_key" value="other" id="gddealerFflRejectOther"> Other (specify below)</label>

		<div class="gddealerFflReject__otherBox" id="gddealerFflRejectOtherBox">
			<textarea name="reason_other" class="gddealerFflReject__otherTextarea" placeholder="Enter the specific reason the dealer will receive..."></textarea>
		</div>

		<div class="gddealerFflReject__actions">
			<a href="{$data['cancel_url']}" class="gddealerFflReject__btn gddealerFflReject__btn--cancel">Cancel</a>
			<button type="submit" class="gddealerFflReject__btn gddealerFflReject__btn--submit">Reject &amp; notify dealer</button>
		</div>
	</form>
</div>

<script>
(function() {
	var otherRadio = document.getElementById( 'gddealerFflRejectOther' );
	var otherBox   = document.getElementById( 'gddealerFflRejectOtherBox' );
	var form       = document.getElementById( 'gddealerFflRejectForm' );
	if ( !form ) return;
	form.addEventListener( 'change', function() {
		if ( otherRadio && otherRadio.checked ) { otherBox.classList.add( 'is-visible' ); }
		else { otherBox.classList.remove( 'is-visible' ); }
	} );
})();
</script>
TEMPLATE_EOT ],
			[ 'name' => 'feedUploadForm', 'location' => 'front', 'data' => '$data', 'content' => <<<'TEMPLATE_EOT'
<div style="max-width:720px;margin:0 auto">
	<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:8px">
		<h2 style="margin:0;font-size:1.4em;font-weight:700;color:#0f172a">Upload Feed File</h2>
		<a href="{$data['tab_urls']['feedSettings']}" class="ipsButton ipsButton--light ipsButton--small">&larr; Back to Feed Settings</a>
	</div>
	<div class="ipsBox" style="padding:24px">
		{$data['form']}
	</div>
</div>
TEMPLATE_EOT ],
			[ 'name' => 'subscription', 'location' => 'front', 'data' => '$dealer, $sub, $billingNote, $tabUrls, $plans', 'content' => <<<'TEMPLATE_EOT'
<div>

	<div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap">
		<div class="ipsBox" style="flex:1 1 200px;padding:16px;text-align:center">
			<div style="color:#666;font-size:0.9em">{lang="gddealer_front_subscription_current"}</div>
			<div style="font-size:1.6em;font-weight:bold;margin-top:4px">{$sub['tier_label']}</div>
		</div>
		<div class="ipsBox" style="flex:1 1 200px;padding:16px;text-align:center">
			<div style="color:#666;font-size:0.9em">{lang="gddealer_front_subscription_mrr"}</div>
			<div style="font-size:1.6em;font-weight:bold;margin-top:4px">{$sub['mrr']}</div>
		</div>
		<div class="ipsBox" style="flex:1 1 200px;padding:16px;text-align:center">
			<div style="color:#666;font-size:0.9em">{lang="gddealer_front_subscription_status"}</div>
			<div style="font-size:1.2em;font-weight:bold;margin-top:4px">
				{{if $sub['suspended']}}
					<span class="ipsBadge ipsBadge--negative">Suspended</span>
				{{elseif $sub['active']}}
					<span class="ipsBadge ipsBadge--positive">Active</span>
				{{else}}
					<span class="ipsBadge ipsBadge--neutral">Pending Setup</span>
				{{endif}}
			</div>
		</div>
	</div>

	{{if $sub['trial_expires_at']}}
	<div style="margin-bottom:24px;padding:16px;border-radius:8px;border:1px solid {expression="$sub['trial_expiring_soon'] ? '#fca5a5' : 'var(--i-border-color,#e0e0e0)'"};background:{expression="$sub['trial_expiring_soon'] ? '#fff5f5' : '#fff'"}">
		<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
			<div>
				<div style="font-size:0.8em;color:#666;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">Trial Period</div>
				<div style="font-weight:700;font-size:1.05em">
					Expires {$sub['trial_expires_formatted']}
				</div>
				{{if $sub['trial_expiring_soon']}}
					<div style="margin-top:4px;color:#dc2626;font-size:0.9em;font-weight:600">
						Expires in {$sub['trial_days_left']} day{{if $sub['trial_days_left'] !== 1}}s{{endif}} — subscribe to keep your listings live
					</div>
				{{else}}
					<div style="margin-top:4px;color:#666;font-size:0.85em">
						{$sub['trial_days_left']} days remaining on your trial
					</div>
				{{endif}}
			</div>
			{{if $sub['trial_expiring_soon']}}
			<div>
				<a href="{$sub['subscribe_url']}" class="ipsButton ipsButton--primary ipsButton--small">Subscribe Now</a>
			</div>
			{{endif}}
		</div>
	</div>
	{{endif}}

	<p>{$billingNote}</p>

</div>
TEMPLATE_EOT ],
		];

		foreach ( $seeds as $tpl )
		{
			try
			{
				\IPS\Db::i()->replace( 'core_theme_templates', [
					'template_set_id'   => 1,
					'template_app'      => 'gddealer',
					'template_location' => $tpl['location'],
					'template_group'    => 'dealers',
					'template_name'     => $tpl['name'],
					'template_data'     => $tpl['data'],
					'template_content'  => $tpl['content'],
					'template_updated'  => time(),
					'template_version'  => '1.0.202',
				] );
			}
			catch ( \Throwable $e )
			{
				try { \IPS\Log::log( 'upg_10202 replace failed for ' . $tpl['name'] . ': ' . $e->getMessage(), 'gddealer_upg_10202' ); } catch ( \Throwable ) {}
			}

			try
			{
				\IPS\Db::i()->delete( 'core_theme_templates', [
					'template_app=? AND template_location=? AND template_group=? AND template_name=? AND template_set_id=?',
					'gddealer', $tpl['location'], 'dealers', $tpl['name'], 0
				] );
			}
			catch ( \Throwable ) {}
		}

		/* Cache busts — rule #40 */
		try { \IPS\Db::i()->delete( 'core_cache' ); } catch ( \Throwable ) {}
		try { \IPS\Db::i()->delete( 'core_store', [ "store_key LIKE 'theme_%' OR store_key LIKE 'template_%'" ] ); } catch ( \Throwable ) {}
		foreach ( glob( \IPS\ROOT_PATH . '/datastore/template_*' ) ?: [] as $f ) { @unlink( $f ); }
		try { unset( \IPS\Data\Store::i()->extensions ); }   catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->applications ); } catch ( \Throwable ) {}
		try { unset( \IPS\Data\Store::i()->themes ); }       catch ( \Throwable ) {}
		try { \IPS\Data\Cache::i()->clearAll(); }            catch ( \Throwable ) {}

		return TRUE;
	}
}

class upgrade extends _upgrade {}
