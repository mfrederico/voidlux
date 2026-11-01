// RuleWords: a rule of kit-events.js in plain words, shared by the Events tab and the Studio's card.
//   on die → if self.burning is yes and count fires (game) < 3 → SetBurning → nearby every enemy (radius 80)
const RuleWords = {
  OPS: { is: 'is', not: 'is not', '>': '>', '<': '<', '>=': '≥', '<=': '≤', has: 'has' },
  say(r) {
    const lim = a => [a.max && `max ${a.max}`, a.once && 'once', a.cooldown && `every ${a.cooldown}s at most`, a.chance != null && a.chance < 1 && `${Math.round(a.chance * 100)}% chance`, a.delay && `after ${a.delay}s`].filter(Boolean).join(', ');
    const args = a => Object.entries(a.args || {}).map(([k, v]) => `${k} ${v}`).join(', ');
    const to = a => a.to ? ` → ${a.to.target}${a.to.who ? ' ' + a.to.who.replace('group:', 'every ') : ''}${a.to.args ? ' (' + Object.entries(a.to.args).map(([k, v]) => `${k} ${v}`).join(', ') + ')' : ''}` : '';
    const op = c => this.OPS[c.op] || c.op;
    const cond = c => c.op === 'chance' ? `${Math.round(c.value * 100)}% chance` : c.state != null ? `${c.state} ${op(c)} ${c.value}` : c.count != null ? `count ${c.count}${c.on === 'game' ? ' (game)' : ''} ${op(c)} ${c.value}` : `${c.field} ${op(c)} ${c.value}`;
    return `on ${r.on}` + ((r.if || []).length ? ' → if ' + r.if.map(cond).join(' and ') : '')
      + ' → ' + (r.do || []).map(a => (a.emit ? `fire “${a.emit}”` : a.fn) + to(a) + ((args(a) || lim(a)) ? ` (${[args(a), lim(a)].filter(Boolean).join('; ')})` : '')).join(', then ');
  },
};
