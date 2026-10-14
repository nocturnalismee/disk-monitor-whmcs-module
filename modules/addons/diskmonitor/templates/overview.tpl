<h2>{$title}</h2>
<p>Server OK: {$summary.servers_ok} | Gagal: {$summary.servers_failed} | Email 24 jam: {$summary.emails_sent}</p>
<table class="datatable" width="100%">
<tr><th>Server</th><th>User</th><th>Domain</th><th>%</th><th>Level</th><th>Status</th><th>Terakhir kirim</th></tr>
{foreach from=$events item=e}
<tr><td>{$e.server_id|escape}</td><td>{$e.user|escape}</td><td>{$e.domain|escape}</td><td>{$e.pct|escape}</td><td>{$e.level|escape}</td><td>{$e.status|escape}</td><td>{$e.last_sent_at|escape}</td></tr>
{/foreach}
</table>
