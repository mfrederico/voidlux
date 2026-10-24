"""The studio's agent runner: one-shot Claude Code sessions in a game's directory, their stream parsed into events the
dashboard shows live, and the pipeline (plan -> build each task -> soak -> review -> checkpoint) built from those runs.

A run is `claude -p <prompt> --output-format stream-json --verbose [--resume <session>]` in the game's directory, with
--permission-mode acceptEdits and a Bash allowlist (node --check, git, the soak client, ls/grep/cat). Edits inside the
game are accepted; anything else the agent asks for is refused and it adapts. Setting "jail": true in config.json
additionally confines the run with bubblewrap (studio/jail.sh: the OS read-only, the kit read-only, the game
read-write, nothing else of the machine visible); with the jail as the boundary the operator may also set
"permissions": "off" so the confined agent runs without prompts, the way the tiknix AI Builder does. That is an
operator's choice made in the config file, never the default. Billing is the account logged into the `claude` CLI on
this machine; no API key is injected.

Events are dicts {t: unix time, kind, ...} kept per game (the last 2000) and pushed to every SSE listener. Kinds:
  text (assistant prose), tool (a tool call), result (a tool result, trimmed), stage (pipeline progress), soak (a soak
  report), done (a run finished: cost, turns), error, note (the studio talking), idle.
"""
import json, os, queue, re, subprocess, threading, time

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..'))
ALLOWED_TOOLS = ['Bash(node --check:*)', 'Bash(node --version)', 'Bash(git:*)', 'Bash(ls:*)', 'Bash(cat:*)', 'Bash(head:*)', 'Bash(tail:*)', 'Bash(wc:*)',
                 'Bash(grep:*)', 'Bash(find:*)', 'Bash(diff:*)', 'Bash(for f in js/*.js; do node --check:*)', 'Bash(../../studio/bin/soak:*)',
                 'Bash(curl -sf -X POST http://127.0.0.1:*)', 'Bash(mkdir:*)', 'Bash(cp:*)', 'Bash(mv:*)', 'Bash(python3 -c:*)']


class GameAgent:
    def __init__(self, slug, gamedir, cfg, port, hooks):
        self.slug, self.dir, self.cfg, self.port, self.hooks = slug, gamedir, cfg, port, hooks
        self.events, self.listeners, self.lock = [], [], threading.Lock()
        self.proc, self.busy, self.stop_flag, self.thread, self.mode = None, False, False, None, ''
        self.state_file = os.path.join(gamedir, '.studio', 'agent.json')
        self.state = self._load_state()
        self.seq = 0

    # ---- state: the resumable session id, kept beside the game (gitignored) ----
    def _load_state(self):
        try: return json.load(open(self.state_file))
        except Exception: return {'session': None, 'runs': 0, 'cost': 0.0}
    def _save_state(self):
        os.makedirs(os.path.dirname(self.state_file), exist_ok=True)
        tmp = self.state_file + '.tmp'
        json.dump(self.state, open(tmp, 'w'), indent=1); os.replace(tmp, self.state_file)

    # ---- events ----
    def emit(self, kind, **kw):
        ev = dict(kind=kind, t=round(time.time(), 2), **kw)
        with self.lock:
            self.seq += 1; ev['id'] = self.seq; self.events.append(ev)
            if len(self.events) > 2000: del self.events[:500]
            for q in list(self.listeners):
                try: q.put_nowait(ev)
                except queue.Full: pass
        try:
            os.makedirs(os.path.join(self.dir, '.studio'), exist_ok=True)
            with open(os.path.join(self.dir, '.studio', 'events.log'), 'a') as f: f.write(json.dumps(ev) + '\n')
        except Exception: pass
        return ev
    def listen(self):
        q = queue.Queue(maxsize=5000)
        with self.lock: self.listeners.append(q)
        return q
    def unlisten(self, q):
        with self.lock:
            if q in self.listeners: self.listeners.remove(q)
    def since(self, n):
        with self.lock: return [e for e in self.events if e['id'] > n]
    def status(self):
        return {'busy': self.busy, 'mode': self.mode, 'session': self.state.get('session'), 'runs': self.state.get('runs', 0), 'cost': round(self.state.get('cost', 0.0), 4), 'last': self.events[-1] if self.events else None}

    # ---- one claude run, blocking; returns the result dict ----
    def command(self, prompt, resume, max_turns):
        cfg = self.cfg
        cmd = ['claude', '-p', prompt, '--output-format', 'stream-json', '--verbose', '--max-turns', str(max_turns or cfg.get('maxTurns', 60))]
        if cfg.get('model'): cmd += ['--model', cfg['model']]
        if resume: cmd += ['--resume', resume]
        jail = bool(cfg.get('jail', False)) and os.path.exists('/usr/bin/bwrap')
        if jail and cfg.get('permissions') == 'off': cmd += ['--permission-mode', 'bypassPermissions']  # only inside the jail, only when the operator says so
        else: cmd += ['--permission-mode', 'acceptEdits', '--allowedTools'] + ALLOWED_TOOLS
        if jail: cmd = [os.path.join(HERE, 'jail.sh'), self.dir] + cmd
        return cmd, jail

    def run(self, prompt, resume=True, fresh=False, max_turns=None, label=''):
        sid = None if fresh else self.state.get('session')
        cmd, jail = self.command(prompt, sid if resume else None, max_turns)
        env = {k: v for k, v in os.environ.items() if not k.startswith('CLAUDE') and k != 'ANTHROPIC_API_KEY'}
        env['STUDIO_URL'] = 'http://127.0.0.1:%d' % self.port; env['VOIDLUX_GAME'] = self.slug
        self.emit('note', text=('%s: ' % label if label else '') + 'agent run starts' + (' (resumes %s)' % sid[:8] if resume and sid else ' (new session)') + (' in the jail' if jail else ''))
        result = {'ok': False, 'text': '', 'cost': 0.0, 'turns': 0}
        try:
            self.proc = subprocess.Popen(cmd, cwd=self.dir, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, bufsize=1)
        except FileNotFoundError as e:
            self.emit('error', text='cannot start the agent: %s' % e); return result
        last_text = []
        for line in self.proc.stdout:
            line = line.strip()
            if not line: continue
            try: m = json.loads(line)
            except ValueError: self.emit('note', text=line[:500]); continue
            kind = m.get('type')
            if kind == 'system' and m.get('subtype') == 'init':
                if m.get('session_id'): self.state['session'] = m['session_id']; self._save_state()
                self.emit('note', text='model %s · %d tools' % (m.get('model', '?'), len(m.get('tools', []))))
            elif kind == 'assistant':
                for c in (m.get('message') or {}).get('content') or []:
                    if c.get('type') == 'text' and c.get('text', '').strip(): last_text.append(c['text']); self.emit('text', text=c['text'])
                    elif c.get('type') == 'tool_use': self.emit('tool', name=c.get('name'), input=self._brief_input(c.get('name'), c.get('input') or {}))
            elif kind == 'user':
                for c in (m.get('message') or {}).get('content') or []:
                    if c.get('type') == 'tool_result':
                        body = c.get('content'); txt = body if isinstance(body, str) else ' '.join(x.get('text', '') for x in body or [] if isinstance(x, dict))
                        self.emit('result', text=(txt or '')[:1200], error=bool(c.get('is_error')))
            elif kind == 'result':
                result.update(ok=not m.get('is_error'), text=m.get('result') or '\n'.join(last_text[-1:]), cost=float(m.get('total_cost_usd') or 0), turns=int(m.get('num_turns') or 0), ms=m.get('duration_ms'), subtype=m.get('subtype'))
                if m.get('session_id'): self.state['session'] = m['session_id']
            if self.stop_flag: break
        err = self.proc.stderr.read() if self.proc.stderr else ''
        rc = self.proc.wait(); self.proc = None
        if self.stop_flag: self.emit('note', text='run stopped'); result['stopped'] = True
        elif rc != 0 and not result['ok']: self.emit('error', text='the agent exited %d: %s' % (rc, (err or '').strip()[-800:] or 'no message'))
        self.state['runs'] = self.state.get('runs', 0) + 1; self.state['cost'] = self.state.get('cost', 0.0) + result['cost']; self._save_state()
        self.emit('done', ok=result['ok'], cost=round(result['cost'], 4), turns=result['turns'], text=(result['text'] or '')[:2000], label=label)
        return result

    def _brief_input(self, name, inp):
        if name in ('Edit', 'Write', 'Read', 'MultiEdit'): return {'file': os.path.relpath(inp.get('file_path', ''), self.dir) if inp.get('file_path') else ''}
        if name == 'Bash': return {'command': (inp.get('command') or '')[:300]}
        if name in ('Grep', 'Glob'): return {'pattern': inp.get('pattern'), 'path': inp.get('path')}
        return {k: (str(v)[:120]) for k, v in list(inp.items())[:4]}

    def stop(self):
        self.stop_flag = True
        p = self.proc
        if p:
            try: p.terminate()
            except Exception: pass

    # ---- the public starts: chat (one run) and the pipeline, each on a thread ----
    def start(self, mode, prompt='', **kw):
        if self.busy: raise RuntimeError('an agent run is already going for this game')
        self.busy, self.stop_flag, self.mode = True, False, mode
        target = {'chat': self._chat, 'pipeline': self._pipeline, 'review': self._review_only, 'fix': self._fix_only}[mode]
        self.thread = threading.Thread(target=self._guard, args=(target, prompt, kw), daemon=True); self.thread.start()
    def _guard(self, fn, prompt, kw):
        try: fn(prompt, **kw)
        except Exception as e: self.emit('error', text='studio: %s' % e)
        finally: self.busy, self.mode = False, ''; self.emit('idle')

    def _chat(self, prompt, **kw):
        self.run(prompt, label='chat')
        self.hooks['checkpoint'](self.slug, 'after chat: ' + prompt[:60])

    # ---- the pipeline: plan, build tasks, soak, review, checkpoint. Each stage is a run; the studio checks between them ----
    PLAN = ("You are the game designer and planner. Read AGENTS.md, ../../kit/AGENTS.md and GAME.md (the Brief). Write GAME.md's "
            "Design section (the fantasy, the core loop, how a run ends, what the player collects or builds, the enemies and how each "
            "threatens, the levels or waves, the SFX keys and music theme names the game needs, and what sells it: the hook, the "
            "screenshot, the price). Then the Acceptance criteria: 6 to 12 checkable claims, each a line starting '- [ ] '. Then "
            "Tasks: 4 to 8 ordered build tasks, each a line starting '- [ ] T<n>: ' naming what to build and ending with "
            "'(check: <how the reviewer verifies it>)'. Keep the three criteria already there. Do not write game code in this turn. "
            "Keep the game buildable on the kit in plain canvas JavaScript, small enough to finish. Commit GAME.md when done.")
    BUILD = ("Build task {task} from GAME.md: {text}\nFollow AGENTS.md. Work only on this task. When it works: node --check every "
             "js file, run `../../studio/bin/soak 60 4` and fix anything it reports until ok is true, tick the task's box in GAME.md, "
             "add a line to GAME.md's Log, and commit with a plain-English message.")
    FIX = ("The headless soak reported problems. Fix them, re-run `../../studio/bin/soak 60 4` until ok is true, and commit.\n"
           "Report:\n{report}")
    REVIEW = ("You are the reviewer, independent of whoever built this. Read GAME.md's Acceptance criteria and Tasks. For each "
              "criterion decide pass or fail by reading the code and by running `../../studio/bin/soak 90 4` (and `node --check`). "
              "No vague claims: a pass needs evidence you saw. Change nothing except ticking criteria that pass in GAME.md and "
              "appending a Log line 'review: <n> pass, <m> fail'. Finish your reply with one line exactly: "
              "VERDICT: {{\"pass\": true|false, \"failures\": [\"<criterion>: <why>\", ...]}}")
    REWORK = ("The reviewer failed these acceptance criteria:\n{failures}\nFix them following AGENTS.md: change the code, node --check, "
              "run `../../studio/bin/soak 60 4` until ok is true, update GAME.md's Log, commit.")

    def _tasks(self):
        try: text = open(os.path.join(self.dir, 'GAME.md'), encoding='utf-8').read()
        except OSError: return []
        tasks = []
        for s in re.split(r'^## ', text, flags=re.M):
            if s.startswith('Tasks'):
                for m in re.finditer(r'^- \[( |x)\] (T\d+)?:?\s*(.+)$', s, flags=re.M): tasks.append({'done': m.group(1) == 'x', 'id': m.group(2) or 'T%d' % (len(tasks) + 1), 'text': m.group(3).strip()})
        return tasks

    def _pipeline(self, prompt, rounds=2, **kw):
        cp = self.hooks['checkpoint']; soak = self.hooks['soak']
        self.emit('stage', stage='plan', text='planning the game from its brief')
        if not self._tasks() or prompt:
            r = self.run(self.PLAN + ('\nThe producer adds: ' + prompt if prompt else ''), label='plan')
            if self.stop_flag or not r['ok']: return
            cp(self.slug, 'plan')
        tasks = self._tasks()
        if not tasks: self.emit('error', text='the plan has no tasks: GAME.md needs "- [ ] T1: ..." lines under ## Tasks'); return
        self.emit('stage', stage='build', text='%d tasks, %d done' % (len(tasks), sum(t['done'] for t in tasks)), tasks=tasks)
        for t in tasks:
            if t['done']: continue
            if self.stop_flag: return
            self.emit('stage', stage='task', text='%s: %s' % (t['id'], t['text'][:120]), task=t['id'])
            r = self.run(self.BUILD.format(task=t['id'], text=t['text']), label=t['id'])
            cp(self.slug, '%s built' % t['id'])
            if self.stop_flag or not r['ok']: return
            self.emit('stage', stage='tasks', tasks=self._tasks())
        for i in range(rounds + 1):
            if self.stop_flag: return
            self.emit('stage', stage='soak', text='headless soak, 90 game-seconds at 4x')
            rep = soak(self.slug, 90, 4)
            if rep.get('ok'): break
            if i == rounds: self.emit('error', text='the soak still fails after %d fix rounds' % rounds); break
            self.emit('stage', stage='fix', text='soak failed: asking the agent to fix it (round %d)' % (i + 1))
            r = self.run(self.FIX.format(report=json.dumps({k: rep.get(k) for k in ('status', 'errors', 'checks', 'browserErrors', 'scenes', 'fps', 'slowFrames')}, indent=1)[:6000]), label='fix')
            cp(self.slug, 'fix round %d' % (i + 1))
            if not r['ok']: return
        for i in range(rounds + 1):
            if self.stop_flag: return
            self.emit('stage', stage='review', text='independent review against the acceptance criteria (round %d)' % (i + 1))
            verdict = self._review()
            if verdict is None: return
            cp(self.slug, 'review round %d' % (i + 1))
            if verdict.get('pass'): self.emit('stage', stage='accepted', text='the reviewer accepted every criterion'); break
            fails = verdict.get('failures') or ['the reviewer gave no detail']
            if i == rounds: self.emit('stage', stage='rejected', text='still failing after %d rework rounds' % rounds, failures=fails); break
            self.emit('stage', stage='rework', text='%d criteria failed: reworking' % len(fails), failures=fails)
            r = self.run(self.REWORK.format(failures='\n'.join('- ' + f for f in fails)), label='rework')
            cp(self.slug, 'rework round %d' % (i + 1))
            if not r['ok']: return
        cp(self.slug, 'pipeline done')
        self.emit('stage', stage='done', text='pipeline finished')

    def _review(self):
        r = self.run(self.REVIEW, fresh=True, label='review', max_turns=40)
        if not r['ok'] and not r['text']: return None
        m = re.search(r'VERDICT:\s*(\{.*\})', r['text'] or '', flags=re.S)
        if not m: self.emit('note', text='the reviewer gave no VERDICT line; treating it as a fail'); return {'pass': False, 'failures': ['the reviewer ended without a verdict']}
        try: return json.loads(m.group(1))
        except ValueError: return {'pass': False, 'failures': ['unreadable verdict: ' + m.group(1)[:200]]}
    def _review_only(self, prompt, **kw):
        v = self._review(); self.hooks['checkpoint'](self.slug, 'review')
        if v is not None: self.emit('stage', stage='accepted' if v.get('pass') else 'rejected', text='review: ' + ('pass' if v.get('pass') else 'fail'), failures=v.get('failures') or [])
    def _fix_only(self, prompt, **kw):
        rep = self.hooks['soak'](self.slug, 90, 4)
        if rep.get('ok'): self.emit('stage', stage='accepted', text='the soak already passes'); return
        self.run(self.FIX.format(report=json.dumps(rep, indent=1)[:6000]), label='fix'); self.hooks['checkpoint'](self.slug, 'fix')
