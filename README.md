# Disk Monitor — WHMCS Addon Module (MVP)

Monitoring disk penuh akun cPanel di banyak server WHM + email otomatis ke pelanggan via WHMCS.

- **Spec:** `docs/superpowers/specs/2026-10-14-diskmonitor-design.md`
- **Scope MVP:** deteksi warning ≥85% / critical ≥95%, cooldown 24 jam, email via WHMCS Email Template, auto-resolve <80%.
- **Struktur (rencana):** `modules/addons/diskmonitor/` (addon native) + `agent/agent.php` (server root).

Lihat spec untuk detail arsitektur, skema DB, dan acceptance criteria.
