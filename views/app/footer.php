<span class="text-secondary small">VOIDLUX — the swarm does not sleep · <a href="/centipede/" target="_blank">centipede</a> · <a href="/kit/AGENTS.md" target="_blank">kit</a></span>
<?php if (str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/agents')): ?>
<script>
/* the runtime's Skills & plugins badge counts the app's own skills only; once its page has listed the plugins, say both */
(() => { const box = document.getElementById('pluginsInstalled'), badge = document.querySelector('#skillsTabBtn .badge'); if (!box || !badge) return; const skills = badge.textContent.trim();
  new MutationObserver(() => { const n = box.querySelectorAll('.list-group-item').length; if (n || box.textContent.includes('No plugins')) badge.textContent = `${skills} skills · ${n} plugins`; }).observe(box, { childList: true, subtree: true }); })();
</script>
<?php endif; ?>
