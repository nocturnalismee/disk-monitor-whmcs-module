<?php
namespace DiskMonitor;

class Runner {
    public static function runCheck($db, callable $mailer, callable $whmFactory, array $settings, string $now): array {
        $warn = (float) $settings['warn_pct'];
        $crit = (float) $settings['crit_pct'];
        $cool = (int) $settings['cooldown_h'];
        $reso = (float) $settings['resolve_below'];
        $summary = ['servers_ok' => 0, 'servers_failed' => 0, 'emails_sent' => 0, 'events_open' => 0];
        $hostingRows = $db->getHostingRows();

        foreach ($db->getServers() as $server) {
            try {
                $accounts = Collector::pollServer($server, $whmFactory($server));
                $summary['servers_ok']++;
            } catch (\Throwable $e) {
                $summary['servers_failed']++;
                $db->insertLog(['event_id' => null, 'server_id' => (int) $server['id'], 'user' => '', 'email_to' => '', 'template' => '', 'result' => 'failed', 'message' => substr($e->getMessage(), 0, 500), 'created_at' => $now]);
                continue;
            }
            foreach ($accounts as $acct) {
                if (!empty($acct['unlimited']) || $acct['pct'] === null) {
                    $db->upsertCache($acct, 'api', $now);
                    continue;
                }
                $level = Rules::evaluate((float) $acct['pct'], !empty($acct['over_quota']), $warn, $crit);
                $db->upsertCache($acct, 'api', $now);
                if ($level === 'none') {
                    if ((float) $acct['pct'] < $reso) {
                        $db->resolveEvent((int) $server['id'], (string) $acct['user'], $now);
                    }
                    continue;
                }
                $event = $db->findOpenEvent((int) $server['id'], (string) $acct['user']);
                $decision = Notifier::decide($event, $level, $cool, $now);
                if ($event === null) {
                    $eventId = $db->openEvent((int) $server['id'], (string) $acct['user'], (string) $acct['domain'], $level, $now);
                    $event = ['id' => $eventId, 'level' => $level, 'status' => 'open', 'last_sent_at' => null];
                    $decision = Notifier::decide(null, $level, $cool, $now);
                }
                if ($decision === 'no_change') {
                    continue;
                }
                if ($decision === 'skip_cooldown') {
                    $db->insertLog(['event_id' => $event['id'] ?? null, 'server_id' => (int) $server['id'], 'user' => (string) $acct['user'], 'email_to' => '', 'template' => '', 'result' => 'skipped_cooldown', 'message' => $level, 'created_at' => $now]);
                    continue;
                }
                $customer = Resolver::resolve(['user' => (string) $acct['user'], 'domain' => (string) $acct['domain'], 'server_id' => (int) $server['id']], $hostingRows);
                if ($customer === null) {
                    $db->updateEvent((int) $event['id'], ['status' => 'unmapped', 'last_seen' => $now]);
                    $db->insertLog(['event_id' => (int) $event['id'], 'server_id' => (int) $server['id'], 'user' => (string) $acct['user'], 'email_to' => '', 'template' => '', 'result' => 'unmapped', 'message' => (string) $acct['domain'], 'created_at' => $now]);
                    continue;
                }
                $vars = Notifier::buildCustomVars($acct, $acct['pct'], $customer, 'https://' . $server['hostname'] . ':2083');
                $sent = $mailer((int) $customer['userid'], (string) $settings['email_template'], $vars);
                if ($sent) {
                    $db->updateEvent((int) $event['id'], ['level' => $level, 'last_sent_at' => $now, 'last_seen' => $now, 'send_count+1' => true]);
                    $db->insertLog(['event_id' => (int) $event['id'], 'server_id' => (int) $server['id'], 'user' => (string) $acct['user'], 'email_to' => $customer['email'], 'template' => (string) $settings['email_template'], 'result' => 'sent', 'message' => $level . ' ' . $vars['pct'] . '%', 'created_at' => $now]);
                    $summary['emails_sent']++;
                } else {
                    $db->insertLog(['event_id' => (int) $event['id'], 'server_id' => (int) $server['id'], 'user' => (string) $acct['user'], 'email_to' => $customer['email'], 'template' => (string) $settings['email_template'], 'result' => 'failed', 'message' => 'mailer returned false', 'created_at' => $now]);
                }
            }
        }
        return $summary;
    }
}
