<?php
if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}
add_hook('DailyCronJob', 1, function () {
    try {
        \WHMCS\Database\Capsule::table('mod_diskmonitor_cache')->where('updated_at', '<', date('Y-m-d H:i:s', strtotime('-7 days')))->delete();
        \WHMCS\Database\Capsule::table('mod_diskmonitor_log')->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-90 days')))->delete();
        \WHMCS\Database\Capsule::table('mod_diskmonitor_events')->where('status', 'open')->where('last_seen', '<', date('Y-m-d H:i:s', strtotime('-7 days')))->update(['status' => 'resolved', 'resolved_at' => date('Y-m-d H:i:s')]);
    } catch (\Throwable $e) {
        logActivity('DiskMonitor housekeeping failed: ' . substr($e->getMessage(), 0, 300));
    }
});
