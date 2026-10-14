<h2>{$title|escape}</h2>
{$notice nofilter}
<p><a href="{$modulelink|escape}&action=checknow{$token_qs|escape}" class="btn btn-primary">{$checknow|escape}</a>
<a href="{$modulelink|escape}&action=sendtest{$token_qs|escape}" class="btn btn-default">{$sendtest|escape}</a></p>
<p>Server OK: {$summary.servers_ok|escape} | Gagal: {$summary.servers_failed|escape} | Email 24 jam: {$summary.emails_sent_24h|escape}{if isset($summary.events_open)} | Open: {$summary.events_open|escape}{/if}</p>
<table class="datatable" width="100%">
<tr><th>Server</th><th>User</th><th>Domain</th><th>%</th><th>Level</th><th>Status</th><th>Terakhir kirim</th></tr>
{foreach from=$events item=e}
<tr><td>{$e.server_id|escape}</td><td>{$e.user|escape}</td><td>{$e.domain|escape}</td><td>{$e.pct|escape}</td><td>{$e.level|escape}</td><td>{$e.status|escape}</td><td>{$e.last_sent_at|escape}</td></tr>
{/foreach}
</table>
