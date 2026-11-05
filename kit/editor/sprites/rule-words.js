// RuleWords: a rule of kit-events.js in plain words, shared by the Events tab and the Studio's card.
//   on die → if self.burning is yes and count fires (game) < 3 → SetBurning → nearby every enemy (radius 80)
const RuleWords = {
  /* a person's words for the game's names: AwardPoints → award points, screen-open → screen open; a thing by its own name when the game gives one */
  names: {},
  nice(k) { k = String(k ?? ''); if (this.names[k]) return this.names[k]; if (k.startsWith('group:')) return 'every ' + this.nice(k.slice(6)); return k.replace(/[_-]+/g, ' ').replace(/([a-z0-9])([A-Z])/g, '$1 $2').replace(/:/g, ' ').trim().toLowerCase(); },
  OPS: { is: 'is', not: 'is not', '>': 'is more than', '<': 'is less than', '>=': 'is at least', '<=': 'is at most', has: 'has' },
  say(r) {
    const n = k => this.nice(k);
    const lim = a => [a.max && `at most ${a.max} time${a.max == 1 ? '' : 's'}`, a.once && 'only the first time', a.cooldown && `then wait ${a.cooldown}s`, a.chance != null && a.chance < 1 && `${Math.round(a.chance * 100)}% of the time`, a.delay && `after ${a.delay}s`].filter(Boolean).join(', ');
    const args = a => Object.entries(a.args || {}).map(([k, v]) => { const val = typeof v === 'string' && !v.startsWith('#') ? n(v) : v; return a.fn && n(a.fn).endsWith(n(k)) ? val : `${n(k)} ${val}`; }).join(', '); // play sound (spotted), not play sound (sound spotted)
    const to = a => a.to ? ` on ${n(a.to.target)}${a.to.who ? ' (' + n(a.to.who) + ')' : ''}${a.to.args ? ' (' + Object.entries(a.to.args).map(([k, v]) => `${n(k)} ${v}`).join(', ') + ')' : ''}` : '';
    const op = c => this.OPS[c.op] || c.op;
    const cond = c => c.op === 'chance' ? `${Math.round(c.value * 100)}% of the time` : c.state != null ? `${n(c.state)} ${op(c)} ${c.value}` : c.count != null ? `the count “${c.count}”${c.on === 'game' ? ' (whole game)' : ''} ${op(c)} ${c.value}` : `${n(c.field)} ${op(c)} ${c.value}`;
    const act = a => (a.emit ? `start “${n(a.emit)}”` : a.fn === 'Count' ? 'count up' : a.fn === 'SetCount' ? 'set a count' : n(a.fn)) + to(a) + ((args(a) || lim(a)) ? ` (${[args(a), lim(a)].filter(Boolean).join('; ')})` : '');
    return `When ${n(r.on)}` + ((r.if || []).length ? ', if ' + r.if.map(cond).join(' and ') : '') + ': ' + ((r.do || []).map(act).join(', then ') || 'nothing yet');
  },
};
