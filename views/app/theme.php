<?php /* VoidLux over the runtime's design system: the void (near-black), ember (#ff6600), gold (#ffcc00), a monospace
         voice. Tokens first (the runtime's components read them), then the few shapes the forge's own pages add. */ ?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&display=swap">
<style>
:root, [data-bs-theme="light"], [data-bs-theme="dark"] {
  --bs-body-bg:#060608; --bs-body-color:#e8e8e8; --bs-secondary-color:#9a9a9a; --bs-tertiary-color:#6a6a6a; --bs-border-color:#232323; --bs-emphasis-color:#ffffff;
  --bs-primary:#ff6600; --bs-primary-rgb:255,102,0; --bs-link-color:#ffcc00; --bs-link-color-rgb:255,204,0; --bs-link-hover-color:#ffe066;
  --bs-body-font-family:'IBM Plex Mono','Courier New',ui-monospace,monospace; --bs-body-font-size:0.92rem;
  --bs-border-radius:0.4rem; --bs-border-radius-sm:0.3rem; --bs-border-radius-lg:0.6rem; --bs-border-radius-xl:0.8rem;
  --ui-surface:#111113; --ui-surface-soft:#17171a; --ui-surface-inset:#0a0a0c;
  --ui-shadow:0 1px 2px rgba(0,0,0,0.5); --ui-shadow-soft:0 1px 2px rgba(0,0,0,0.5), 0 10px 26px -20px rgba(255,102,0,0.25); --ui-shadow-lift:0 24px 48px -20px rgba(0,0,0,0.7), 0 0 0 1px rgba(255,102,0,0.15);
  --ui-primary:#ff6600; --ui-accent-order:#ffcc00; --ui-accent-report:#ff4d6d; --ui-accent-system:#7df9ff; --ui-accent-mgmt:#c9b8ff;
  --ui-ff-display:'IBM Plex Mono','Courier New',monospace; --ui-ff-brand:'IBM Plex Mono','Courier New',monospace; --ui-ff-body:'IBM Plex Mono','Courier New',monospace; --ui-ff-mono:'IBM Plex Mono',ui-monospace,monospace;
  --ui-sidebar-bg:#0a0010; --ui-sidebar-text:#b0b0b0; --ui-sidebar-heading:#cc6600; --ui-sidebar-active-bg:#ff6600; --ui-sidebar-active-text:#111; --ui-sidebar-hover-bg:rgba(255,102,0,0.12);
  --bs-success:#00ff66; --bs-success-rgb:0,255,102; --bs-danger:#ff4d6d; --bs-danger-rgb:255,77,109; --bs-warning:#ffcc00; --bs-warning-rgb:255,204,0; --bs-info:#7df9ff; --bs-info-rgb:125,249,255;
}
body { background: var(--bs-body-bg); }
.ui-sidebar, .ui-topbar { border-color: rgba(204,102,0,0.3) !important; }
.ui-topbar { background: linear-gradient(135deg, #0d0015, #060008) !important; backdrop-filter: blur(10px); }
.ui-brand, .navbar-brand, .ui-sidebar .ui-brand { letter-spacing: 3px; text-transform: uppercase; background: linear-gradient(90deg, #ff6600, #ffcc00, #ff6600); background-size: 200% auto; -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent; animation: vl-shimmer 3s linear infinite; }
@keyframes vl-shimmer { to { background-position: 200% center; } }
.btn-primary { --bs-btn-bg:#ff6600; --bs-btn-border-color:#ff6600; --bs-btn-hover-bg:#dd7700; --bs-btn-hover-border-color:#dd7700; --bs-btn-color:#fff; --bs-btn-active-bg:#cc6600; font-weight: 700; letter-spacing: 1px; }
.btn-outline-primary { --bs-btn-color:#ffcc00; --bs-btn-border-color:#444; --bs-btn-hover-bg:transparent; --bs-btn-hover-border-color:#ff6600; --bs-btn-hover-color:#fff; }
.btn-outline-secondary { --bs-btn-color:#ccc; --bs-btn-border-color:#444; --bs-btn-hover-bg:transparent; --bs-btn-hover-border-color:#ff6600; --bs-btn-hover-color:#fff; }
.form-control, .form-select { background:#1a1a1a; border-color:#333; color:#fff; } .form-control:focus, .form-select:focus { border-color:#ff6600; box-shadow: 0 0 0 .2rem rgba(255,102,0,.2); background:#1a1a1a; color:#fff; }
.form-control::placeholder { color:#666; }
.ui-panel, .card, .ui-table-wrap, .table { background: var(--ui-surface); border-color: var(--bs-border-color); color: var(--bs-body-color); }
.table { --bs-table-bg: transparent; --bs-table-color: var(--bs-body-color); --bs-table-border-color:#1c1c1c; --bs-table-hover-bg:#0e0e10; --bs-table-hover-color:#fff; }
h1, h2, h3, .h1, .h2, .h3 { color:#fff; letter-spacing: 1px; } .ui-eyebrow { color:#cc6600; letter-spacing: 3px; text-transform: uppercase; }
.badge.text-bg-success, .bg-success { background-color: rgba(0,255,102,0.15) !important; color:#00ff66 !important; }
a.ui-nav-link.active, .ui-sidebar .active { color:#111 !important; }
.dropdown-menu { --bs-dropdown-bg:#111113; --bs-dropdown-color:#e8e8e8; --bs-dropdown-link-color:#e8e8e8; --bs-dropdown-link-hover-bg:#1c1c1c; --bs-dropdown-link-hover-color:#fff; --bs-dropdown-border-color:#333; }
.list-group { --bs-list-group-bg:#111113; --bs-list-group-color:#e8e8e8; --bs-list-group-border-color:#232323; --bs-list-group-action-color:#e8e8e8; --bs-list-group-action-hover-color:#fff; --bs-list-group-action-hover-bg:rgba(255,102,0,0.14); --bs-list-group-action-active-bg:rgba(255,102,0,0.25); --bs-list-group-action-active-color:#fff; --bs-list-group-active-bg:#ff6600; --bs-list-group-active-border-color:#ff6600; --bs-list-group-active-color:#111; }
.list-group-item-action:hover, .list-group-item-action:focus { color:#fff; } .list-group-item-action:hover small, .list-group-item-action:hover .text-muted, .list-group-item-action:hover .text-secondary { color:#ccc !important; }
.text-muted, .text-secondary { color:#9a9a9a !important; } .bg-light, .table-light { background-color:#17171a !important; color:#e8e8e8 !important; }
.btn-light, .btn-outline-light { --bs-btn-bg:#1a1a1a; --bs-btn-color:#e8e8e8; --bs-btn-border-color:#333; --bs-btn-hover-bg:#222; --bs-btn-hover-color:#fff; --bs-btn-hover-border-color:#ff6600; }
/* Bootstrap's alerts, subtle badges and table rows read the *-bg-subtle / *-text-emphasis / *-border-subtle tokens, which the
   design system leaves at their light-theme values: in the void they were white boxes with white text. Dark tints, bright text. */
:root, [data-bs-theme="light"], [data-bs-theme="dark"] {
  --bs-primary-bg-subtle:#2a1400; --bs-primary-border-subtle:#7a3a00; --bs-primary-text-emphasis:#ffb366;
  --bs-secondary-bg-subtle:#17171a; --bs-secondary-border-subtle:#333; --bs-secondary-text-emphasis:#d0d0d0;
  --bs-success-bg-subtle:#06220f; --bs-success-border-subtle:#0f5a2a; --bs-success-text-emphasis:#66ff99;
  --bs-danger-bg-subtle:#2a0a12; --bs-danger-border-subtle:#7a1c33; --bs-danger-text-emphasis:#ff8fa3;
  --bs-warning-bg-subtle:#2a2000; --bs-warning-border-subtle:#7a5c00; --bs-warning-text-emphasis:#ffdb66;
  --bs-info-bg-subtle:#06232a; --bs-info-border-subtle:#0f5a66; --bs-info-text-emphasis:#9df5ff;
  --bs-light-bg-subtle:#17171a; --bs-light-border-subtle:#333; --bs-light-text-emphasis:#e8e8e8;
  --bs-dark-bg-subtle:#0a0a0c; --bs-dark-border-subtle:#222; --bs-dark-text-emphasis:#e8e8e8;
}
.alert { --bs-alert-color: var(--bs-body-color); } .alert a, .alert .alert-link { color: inherit; text-decoration: underline; }
.alert-light { --bs-alert-bg:#17171a; --bs-alert-color:#e8e8e8; --bs-alert-border-color:#333; }
.ui-notice { background: var(--ui-surface); color: var(--bs-body-color); }
.badge.text-bg-light, .badge.bg-light { background-color:#2a2a2e !important; color:#e8e8e8 !important; }
.toast { --bs-toast-bg:#111113; --bs-toast-color:#e8e8e8; --bs-toast-border-color:#333; --bs-toast-header-bg:#17171a; --bs-toast-header-color:#e8e8e8; }
.form-text { color:#9a9a9a; } .text-body-secondary { color:#9a9a9a !important; } .text-dark { color:#e8e8e8 !important; }
.modal-content { background:#111113; color:#e8e8e8; border-color:#333; }
</style>
<link rel="stylesheet" href="/forge.css">
