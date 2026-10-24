#!/usr/bin/env python3
"""VoidLux Game Studio: the local server behind the dashboard. Standard library only, like centipede's tools/devserver.py,
and it does what that server did for one game for every game in games/:

  http://localhost:8790/                 the VoidLux landing page (public/); http://localhost:8790/studio/ the studio: every game, create one, open one
  http://localhost:8790/game/<slug>      a game's page: play, build (the agent), test (soaks), audio and config editors, history, ship
  http://localhost:8790/games/<slug>/    the game itself (also the URL the headless soak opens)
  http://localhost:8790/editor/sfx/?game=<slug>      the editors, pointed at one game (also tracker/ and config/)
  http://localhost:8790/centipede/       the reference game

  GET  /api/status                       what this machine has: the claude CLI, bwrap, Playwright, the config
  GET  /api/games                        every game with its meta, checkpoints and last soak
  POST /api/games                        { slug, title, brief, template }: a new game from templates/<template>, a git repo, checkpoint cp-0
  GET  /api/games/<slug>                 meta, GAME.md, checkpoints, soaks, agent status
  GET/POST /api/games/<slug>/file?path=  read / write one text file in the game (GAME.md from the dashboard)
  POST /api/games/<slug>/agent           { mode: chat|pipeline|review|fix, prompt }: start an agent run (409 while one runs)
  POST /api/games/<slug>/agent/stop
  GET  /api/games/<slug>/events?since=N  Server-Sent Events: the agent's stream, soaks, stages (replays from N, then live)
  POST /api/games/<slug>/checkpoint      { label }: commit everything and tag it
  POST /api/games/<slug>/rollback        { ref }: back to a checkpoint (git reset --hard; .studio/ is untouched)
  POST /api/games/<slug>/soak?secs&fast&wait  a headless soak (wait=1: answer with the report; else an event when done)
  POST /api/soak/report?game=            the game page's own report at the end of a soak
  GET/POST /api/games/<slug>/sfx         js/sfx.js as JSON / rewrite it (the SFX editor)
  GET  /api/games/<slug>/sounds          every SFX.play key and Music theme name the game's code uses, with where
  GET/POST /api/games/<slug>/tuning      js/tuning.js as JSON / rewrite it (the config editor)
  POST /api/games/<slug>/music/save      { name, snippet } (the tracker); POST .../music/delete { name }
  POST /api/games/<slug>/publish         build dist/ (kit inlined, version stamped) and rsync it to config publish.target

Writes are taken only from this machine (127.0.0.1) unless config allowRemoteWrites is true.
usage: python3 studio/server.py [port]
"""
import base64, hmac, http.server, json, os, re, shutil, subprocess, sys, threading, time, urllib.parse
from datetime import datetime

HERE = os.path.dirname(os.path.abspath(__file__))
ROOT = os.path.abspath(os.path.join(HERE, '..'))
sys.path.insert(0, HERE)
from agent import GameAgent

CFG = json.load(open(os.path.join(HERE, 'config.json')))
PORT = int(sys.argv[1]) if len(sys.argv) > 1 else int(CFG.get('port', 8790))
GAMES = os.path.join(ROOT, 'games'); TEMPLATES = os.path.join(ROOT, 'templates'); KIT = os.path.join(ROOT, 'kit')
os.makedirs(GAMES, exist_ok=True)
LOCAL = {'127.0.0.1', '::1', '::ffff:127.0.0.1'}
SLUG = re.compile(r'^[a-z][a-z0-9-]{1,40}$')
AGENTS, ALOCK = {}, threading.Lock()
MIME = {'.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8', '.json': 'application/json', '.png': 'image/png', '.svg': 'image/svg+xml', '.webmanifest': 'application/manifest+json', '.md': 'text/plain; charset=utf-8', '.txt': 'text/plain; charset=utf-8', '.ico': 'image/x-icon', '.jpg': 'image/jpeg', '.mjs': 'text/javascript; charset=utf-8'}

def git(gamedir, *args, check=False):
    r = subprocess.run(['git', '-C', gamedir] + list(args), capture_output=True, text=True)
    if check and r.returncode: raise ValueError('git %s: %s' % (args[0], (r.stderr or r.stdout).strip()[-400:]))
    return r

def write_atomic(path, text):
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as f: f.write(text)
    os.replace(tmp, path)

def gamedir(slug):
    if not SLUG.match(slug or ''): raise ValueError('bad game slug')
    d = os.path.join(GAMES, slug)
    if not os.path.isdir(d): raise FileNotFoundError('no game ' + slug)
    return d

def meta(slug):
    d = gamedir(slug)
    try: m = json.load(open(os.path.join(d, 'game.json')))
    except Exception: m = {'slug': slug, 'title': slug}
    m['slug'] = slug
    soaks = list_soaks(slug); m['lastSoak'] = soaks[0] if soaks else None
    m['checkpoints'] = len(checkpoints(slug))
    try: m['brief'] = re.search(r'## Brief\s+(.+?)\n\s*\n', open(os.path.join(d, 'GAME.md'), encoding='utf-8').read(), flags=re.S).group(1).strip()[:300]
    except Exception: m['brief'] = ''
    a = AGENTS.get(slug); m['agent'] = a.status() if a else {'busy': False}
    tasks = agent_for(slug)._tasks(); m['tasks'] = {'done': sum(t['done'] for t in tasks), 'total': len(tasks)}
    log = git(d, 'log', '-1', '--format=%cI %s'); m['lastCommit'] = log.stdout.strip()[:140]
    return m

def agent_for(slug):
    with ALOCK:
        a = AGENTS.get(slug)
        if not a: a = AGENTS[slug] = GameAgent(slug, gamedir(slug), CFG, PORT, {'checkpoint': checkpoint, 'soak': run_soak})
        return a

# ---- games ----
def create_game(slug, title, brief, template):
    if not SLUG.match(slug or ''): raise ValueError('a slug is lowercase letters, digits and dashes, 2 to 41 characters')
    if not re.match(r'^[a-z][a-z0-9-]{1,40}$', template or ''): raise ValueError('bad template')
    src = os.path.join(TEMPLATES, template); dst = os.path.join(GAMES, slug)
    if not os.path.isdir(src): raise ValueError('no template ' + template)
    if os.path.exists(dst): raise ValueError('a game named %s exists' % slug)
    title = (title or slug).strip()[:60]; brief = (brief or '').strip()[:4000] or 'A small game on the VoidLux kit.'
    shutil.copytree(src, dst)
    subs = {'__SLUG__': slug, '__TITLE__': title, '__DATE__': datetime.now().strftime('%Y-%m-%d'), '__BRIEF__': brief}
    for base, _, files in os.walk(dst):
        for fn in files:
            p = os.path.join(base, fn)
            if not fn.endswith(('.html', '.js', '.md', '.json', '.webmanifest')): continue
            s = open(p, encoding='utf-8').read(); t = s
            for k, v in subs.items(): t = t.replace(k, v if not fn.endswith('.json') else json.dumps(v)[1:-1])
            if t != s: write_atomic(p, t)
    write_atomic(os.path.join(dst, 'CLAUDE.md'), '# Guidance for agents\n\nThis game\'s guidance is **AGENTS.md** (and the kit\'s, ../../kit/AGENTS.md). Claude Code reads it through the import below.\n\n@AGENTS.md\n')
    m = json.load(open(os.path.join(dst, 'game.json'))); m.update(slug=slug, title=title, template=template, created=subs['__DATE__'], status='new'); write_atomic(os.path.join(dst, 'game.json'), json.dumps(m, indent=1) + '\n')
    git(dst, 'init', '-q', '-b', 'main', check=True); git(dst, 'config', 'user.email', 'studio@voidlux.local'); git(dst, 'config', 'user.name', 'VoidLux Studio')
    git(dst, 'add', '-A', check=True); git(dst, 'commit', '-q', '-m', 'Birth: %s from the %s template' % (title, template), check=True); git(dst, 'tag', '-a', 'cp-0', '-m', 'birth')
    return meta(slug)

def checkpoint(slug, label):
    d = gamedir(slug)
    git(d, 'add', '-A')
    if git(d, 'diff', '--cached', '--quiet').returncode != 0: git(d, 'commit', '-q', '-m', (label or 'checkpoint')[:200])
    n = len(checkpoints(slug)); tag = 'cp-%d' % n
    while git(d, 'rev-parse', '-q', '--verify', 'refs/tags/' + tag).returncode == 0: n += 1; tag = 'cp-%d' % n
    git(d, 'tag', '-a', tag, '-m', (label or 'checkpoint')[:200])
    a = AGENTS.get(slug)
    if a: a.emit('checkpoint', tag=tag, label=label)
    return tag

def checkpoints(slug):
    d = gamedir(slug)
    r = git(d, 'tag', '-l', 'cp-*', '--sort=-creatordate', '--format=%(refname:short)%09%(creatordate:iso8601)%09%(subject)')
    out = []
    for line in r.stdout.splitlines():
        p = line.split('\t'); out.append({'tag': p[0], 'at': p[1] if len(p) > 1 else '', 'label': p[2] if len(p) > 2 else ''})
    return out

def rollback(slug, ref):
    d = gamedir(slug)
    if not re.match(r'^[A-Za-z0-9._/-]{1,80}$', ref or ''): raise ValueError('bad ref')
    a = AGENTS.get(slug)
    if a and a.busy: raise ValueError('stop the agent first')
    checkpoint(slug, 'before rolling back to ' + ref)  # nothing is lost: the state before the rollback is a checkpoint too
    git(d, 'reset', '-q', '--hard', ref, check=True); git(d, 'clean', '-qfd')
    if a: a.emit('note', text='rolled back to ' + ref)
    return checkpoints(slug)

# ---- soaks ----
def list_soaks(slug):
    d = os.path.join(gamedir(slug), '.studio', 'soaks')
    if not os.path.isdir(d): return []
    out = []
    for fn in sorted(os.listdir(d), reverse=True)[:30]:
        if fn.endswith('.json'):
            try: j = json.load(open(os.path.join(d, fn))); out.append({'id': fn[:-5], 'ok': j.get('ok'), 'status': j.get('status'), 'seconds': j.get('seconds'), 'fps': j.get('fps'), 'errors': len(j.get('errors') or []) + len([e for e in j.get('browserErrors') or [] if e.get('kind') != 'network']), 'score': j.get('score'), 'scenes': j.get('scenes'), 'shot': os.path.exists(os.path.join(d, fn[:-5] + '.png'))})
            except Exception: pass
    return out

def run_soak(slug, secs=60, fast=4):
    d = gamedir(slug); sd = os.path.join(d, '.studio', 'soaks'); os.makedirs(sd, exist_ok=True)
    sid = datetime.now().strftime('%Y%m%d-%H%M%S'); shot = os.path.join(sd, sid + '.png')
    a = agent_for(slug); a.emit('note', text='soak: %d game-seconds at %dx' % (secs, fast))
    url = 'http://127.0.0.1:%d/games/%s/' % (PORT, slug)
    env = dict(os.environ, PLAYWRIGHT_ROOT=CFG.get('playwrightRoot', os.path.expanduser('~')), CHROME=CFG.get('chrome', ''))
    try:
        r = subprocess.run(['node', os.path.join(HERE, 'soak.mjs'), url, str(int(secs)), str(int(fast)), shot], cwd=HERE, env=env, capture_output=True, text=True, timeout=700)
        line = [l for l in r.stdout.splitlines() if l.startswith('{')]
        rep = json.loads(line[-1]) if line else {'ok': False, 'status': 'crashed', 'browserErrors': [{'kind': 'runner', 'text': (r.stderr or r.stdout).strip()[-1500:]}]}
    except subprocess.TimeoutExpired: rep = {'ok': False, 'status': 'timeout', 'browserErrors': [{'kind': 'runner', 'text': 'the soak runner did not finish in 700 s'}]}
    except Exception as e: rep = {'ok': False, 'status': 'crashed', 'browserErrors': [{'kind': 'runner', 'text': str(e)}]}
    rep['id'] = sid; rep['secs'] = secs; rep['fast'] = fast
    write_atomic(os.path.join(sd, sid + '.json'), json.dumps(rep, indent=1))
    a.emit('soak', id=sid, ok=rep.get('ok'), status=rep.get('status'), errors=(rep.get('errors') or [])[:10], browserErrors=[e for e in rep.get('browserErrors') or [] if e.get('kind') != 'network'][:10], checks=rep.get('checks'), fps=rep.get('fps'), slowFrames=rep.get('slowFrames'), scenes=rep.get('scenes'), score=rep.get('score'), seconds=rep.get('seconds'))
    return rep

# ---- the editors' files (ported from centipede/tools/devserver.py) ----
WAVES = ('square', 'sawtooth', 'triangle', 'sine', 'noise')
LAYER = {'f0': (1, 20000), 'f1': (1, 20000), 'd': (0.01, 5), 'v': (0, 1), 't': (0, 5000), 'lfo': (0, 200), 'depth': (0, 5000)}
def read_sfx(d):
    try: src = open(os.path.join(d, 'js', 'sfx.js'), encoding='utf-8').read()
    except OSError: return {}
    i = src.find('const SFX_DATA = ')
    return json.loads(src[i + len('const SFX_DATA = '):].rstrip().rstrip(';')) if i >= 0 else {}
def save_sfx(d, data):
    if not isinstance(data, dict): raise ValueError('expected { "<sound key>": { layers: [...] } }')
    clean = {}
    for key, s in data.items():
        if not re.fullmatch(r'[a-z][A-Za-z0-9_]*(:[A-Za-z0-9_-]+)?', key) or not isinstance(s, dict): raise ValueError('bad sound key: %r' % key)
        layers = s.get('layers')
        if not isinstance(layers, list) or not 1 <= len(layers) <= 64: raise ValueError('%s: 1 to 64 layers' % key)
        out = []
        for L in layers:
            if not isinstance(L, dict) or L.get('w') not in WAVES: raise ValueError('%s: a layer needs a wave (%s)' % (key, ', '.join(WAVES)))
            q = {'w': L['w']}
            for f, (lo, hi) in LAYER.items():
                v = L.get(f, 0 if f in ('t', 'lfo', 'depth') else None)
                if not isinstance(v, (int, float)) or isinstance(v, bool) or v != v: raise ValueError('%s: %s is not a number' % (key, f))
                q[f] = round(min(hi, max(lo, v)), 4)
            if not q['lfo'] or not q['depth']: q.pop('lfo'); q.pop('depth')
            if not q['t']: q.pop('t')
            if L.get('crush'): q['crush'] = 1
            if L.get('m'): q['m'] = 1
            if isinstance(L.get('c'), str) and re.fullmatch(r'#[0-9a-fA-F]{6}', L['c']): q['c'] = L['c'].lower()
            out.append(q)
        j = s.get('jitter', 0); entry = {'layers': out}
        if isinstance(j, (int, float)) and not isinstance(j, bool) and 0 < j <= 0.5: entry['jitter'] = round(j, 3)
        clean[key] = entry
    path = os.path.join(d, 'js', 'sfx.js')
    head = open(path, encoding='utf-8').read().split('const SFX_DATA = ')[0] if os.path.exists(path) else '// Sound effects designed in the SFX editor.\n'
    rows = ',\n'.join('  %s: %s' % (json.dumps(k), json.dumps(clean[k], separators=(', ', ': '))) for k in sorted(clean))
    write_atomic(path, head + 'const SFX_DATA = ' + ('{\n' + rows + '\n}' if rows else '{}') + ';\n')
    return len(clean)
def sounds(d):
    out, themes = [], []
    jsdir = os.path.join(d, 'js')
    for fn in sorted(os.listdir(jsdir)) if os.path.isdir(jsdir) else []:
        if not fn.endswith('.js') or fn in ('sfx.js', 'music-themes.js'): continue
        for n, line in enumerate(open(os.path.join(jsdir, fn), encoding='utf-8', errors='replace'), 1):
            for m in re.finditer(r"SFX\.play\(\s*'([A-Za-z0-9_:-]+)'", line): out.append({'key': m.group(1), 'file': 'js/' + fn, 'line': n, 'what': comment_of(line)})
            for m in re.finditer(r"Music\.(?:start|playOnce|fanfare|has)\(\s*'([A-Za-z0-9_-]+)'", line): themes.append({'name': m.group(1), 'file': 'js/' + fn, 'line': n})
    fb = []
    try:
        src = open(os.path.join(jsdir, 'sfx.js'), encoding='utf-8').read(); i = src.find('const SFX_DATA = ')
        fb = sorted(json.loads(src[i + 17:].rstrip().rstrip(';')).keys()) if i >= 0 else []
    except Exception: pass
    return {'sounds': out, 'designed': fb, 'themes': themes}
def comment_of(line):
    i = line.find('//'); return line[i + 2:].strip()[:140] if i >= 0 else ''
TUNING_HEAD = ('// Overrides for js/config.js, written by the config editor. Each entry is a CFG path and its value; only what differs from\n'
               '// js/config.js is here. The editor rewrites this file.\nconst TUNING = ')
TUNING_TAIL = ('\n// applied once, right after js/config.js loads: each path is written onto CFG (a path that no longer exists, or a value\n'
               '// of the wrong kind, is skipped)\n'
               "(function applyTuning() { for (const [path, v] of Object.entries(TUNING)) { const keys = path.split('.'), k = keys.pop(); let o = CFG; for (const q of keys) o = o && o[q]; if (o && typeof o === 'object' && typeof o[k] === typeof v) o[k] = v; } })();\n")
def read_tuning(d):
    try: src = open(os.path.join(d, 'js', 'tuning.js'), encoding='utf-8').read()
    except OSError: return {}
    i = src.find('const TUNING = '); j = src.find(';', i)
    return json.loads(src[i + len('const TUNING = '):j]) if i >= 0 else {}
def save_tuning(d, data):
    if not isinstance(data, dict): raise ValueError('expected { "<CFG path>": value }')
    clean = {}
    for k, v in data.items():
        if not re.fullmatch(r'[A-Z][A-Z0-9_]*(\.[A-Za-z0-9_]+){0,4}', k): raise ValueError('bad path: %r' % k)
        if isinstance(v, bool): clean[k] = v
        elif isinstance(v, (int, float)) and v == v: clean[k] = round(v, 4)
        else: raise ValueError('%s: a number or true/false' % k)
    rows = ',\n'.join('  %s: %s' % (json.dumps(k), json.dumps(clean[k])) for k in sorted(clean))
    write_atomic(os.path.join(d, 'js', 'tuning.js'), TUNING_HEAD + ('{\n' + rows + '\n}' if rows else '{}') + ';' + TUNING_TAIL)
    return len(clean)
MARKER = '// ---- tracker songs below'
def song_pat(name): # a song block: the '// made in the tracker' comment line, 'name: { tracker: true,' ... up to the closing '    ] },' line
    return re.compile(r'    // made in the tracker[^\n]*\n    ' + re.escape(name) + r': \{ tracker: true,[\s\S]*?\n    \] \},\n')
def save_song(d, name, snippet):
    if not re.fullmatch(r'[a-z][a-z0-9_]{0,40}', name or ''): raise ValueError('a song name is lowercase letters, digits and underscores')
    if not isinstance(snippet, str) or ('%s: { tracker: true,' % name) not in snippet or len(snippet) > 400000: raise ValueError('that is not a tracker snippet for ' + str(name))
    path = os.path.join(d, 'js', 'music-themes.js'); src = open(path, encoding='utf-8').read()
    snippet = snippet.rstrip('\n') + '\n'
    if not snippet.startswith('    // made in the tracker'): snippet = '    // made in the tracker\n' + snippet
    pat = song_pat(name)
    if pat.search(src): out, action = pat.sub(lambda m: snippet, src, count=1), 'replaced'
    else:
        mk = re.search(r'^[ \t]*' + re.escape(MARKER), src, flags=re.M)  # the marker LINE (the file's header comment may mention the marker too)
        if not mk: raise ValueError("js/music-themes.js has no '%s' marker inside Music.THEMES" % MARKER)
        j = src.find('\n', mk.start()) + 1  # the line after the marker
        out, action = src[:j] + snippet + src[j:], 'added'
    write_atomic(path + '.bak', src); write_atomic(path, out)
    r = subprocess.run(['node', '--check', path], capture_output=True, text=True)
    if r.returncode: write_atomic(path, src); raise ValueError('the snippet does not parse, nothing saved: ' + r.stderr.strip()[-300:])
    return action
def delete_song(d, name):
    path = os.path.join(d, 'js', 'music-themes.js'); src = open(path, encoding='utf-8').read()
    pat = song_pat(name)
    if not pat.search(src): raise ValueError('no tracker song named %s' % name)
    write_atomic(path + '.bak', src); write_atomic(path, pat.sub('', src, count=1)); return True

# ---- publish: a deployable build with the kit inlined and a version stamp, rsynced if a target is set ----
def publish(slug):
    d = gamedir(slug); dist = os.path.join(d, 'dist')
    sha = git(d, 'rev-parse', '--short', 'HEAD').stdout.strip() or 'dev'; dirty = git(d, 'status', '--porcelain').stdout.strip() != ''
    bid = sha + ('-dirty' if dirty else '')
    if os.path.isdir(dist): shutil.rmtree(dist)
    os.makedirs(os.path.join(dist, 'js'))
    for fn in os.listdir(os.path.join(d, 'js')):
        if fn.endswith('.js'): shutil.copy(os.path.join(d, 'js', fn), os.path.join(dist, 'js', fn))  # (not .bak or .tmp)
    for fn in os.listdir(os.path.join(KIT, 'js')): shutil.copy(os.path.join(KIT, 'js', fn), os.path.join(dist, 'js', fn))
    for extra in ('icons', 'manifest.webmanifest'):
        p = os.path.join(d, extra)
        if os.path.isdir(p): shutil.copytree(p, os.path.join(dist, extra))
        elif os.path.isfile(p): shutil.copy(p, dist)
    html = open(os.path.join(d, 'index.html'), encoding='utf-8').read().replace('../../kit/js/', 'js/')
    write_atomic(os.path.join(dist, 'js', 'version.js'), 'const BUILD = %s;\n' % json.dumps({'id': bid, 'date': datetime.now().strftime('%Y-%m-%d'), 'channel': 'stable'}))
    # assets by slug (/g/<slug>/js/...): on a game's own subdomain nginx forwards the host name only for page requests, so the
    # page is found by host and everything it loads is found by this path, on any host, without a sign-in
    base = '/g/%s/' % slug
    html = re.sub(r'<script src="js/([a-z0-9-]+)\.js"></script>', lambda m: '<script src="%sjs/%s.js?v=%s"></script>' % (base, m.group(1), bid), html)
    html = html.replace('<script src="%sjs/kit-util.js' % base, '<script src="%sjs/version.js?v=%s"></script>\n<script src="%sjs/kit-util.js' % (base, bid, base), 1)
    html = re.sub(r'(href|src)="(icons/|manifest\.webmanifest)', lambda m: '%s="%s%s' % (m.group(1), base, m.group(2)), html)
    write_atomic(os.path.join(dist, 'index.html'), html)
    out = {'build': bid, 'dist': os.path.relpath(dist, ROOT), 'files': sum(len(f) for _, _, f in os.walk(dist))}
    P = CFG.get('publish') or {}
    if P.get('domain') and P.get('proxyDir'):  # capricorn: /var/www/html/.proxy.<slug>.<domain minus tld> sends the subdomain to this studio, which serves dist/
        sname = slug + '.' + P['domain'].rsplit('.', 1)[0]
        write_atomic(os.path.join(P['proxyDir'], '.proxy.' + sname), 'proxyhost=127.0.0.1\nproxyport=%d\n' % PORT)
        out['url'] = 'https://%s.%s/' % (slug, P['domain'])
    target = P.get('target')
    if target:
        r = subprocess.run(['rsync', '-az', '--delete', dist + '/', target.rstrip('/') + '/' + slug + '/'], capture_output=True, text=True)
        if r.returncode: raise ValueError('rsync failed: ' + (r.stderr or r.stdout).strip()[-400:])
        if P.get('urlBase'): out['url'] = P['urlBase'].rstrip('/') + '/' + slug + '/'
    m = json.load(open(os.path.join(d, 'game.json'))); m['published'] = {'build': bid, 'at': datetime.now().isoformat(timespec='seconds'), 'url': out.get('url')}; m['status'] = 'published' if out.get('url') else m.get('status', 'built')
    write_atomic(os.path.join(d, 'game.json'), json.dumps(m, indent=1) + '\n'); git(d, 'add', 'game.json'); git(d, 'commit', '-q', '-m', 'Published build ' + bid)
    agent_for(slug).emit('note', text='published build %s%s' % (bid, (' to ' + out['url']) if out.get('url') else ' to dist/ (no publish target in config.json)'))
    return out

CONFIG_KEYS = ('model', 'maxTurns', 'jail', 'permissions', 'chrome', 'playwrightRoot', 'allowRemoteWrites')
def config_view():
    v = {k: CFG.get(k) for k in CONFIG_KEYS}; v['publish'] = dict(CFG.get('publish') or {}); v['auth'] = {'user': (CFG.get('auth') or {}).get('user', ''), 'set': bool((CFG.get('auth') or {}).get('pass'))}
    v['status'] = status(); v['login'] = claude_login(); return v
def claude_login():
    try: r = subprocess.run(['claude', 'auth', 'status'], capture_output=True, text=True, timeout=25, env={k: v for k, v in os.environ.items() if not k.startswith('CLAUDE')})
    except Exception as e: return {'ok': False, 'text': str(e)}
    txt = (r.stdout or r.stderr or '').strip()
    try: j = json.loads(txt); return {'ok': bool(j.get('loggedIn', j.get('authenticated', True))), 'text': txt, 'data': j}
    except ValueError: return {'ok': r.returncode == 0 and 'not logged in' not in txt.lower(), 'text': txt[:800]}
def config_save(b):
    for k in CONFIG_KEYS:
        if k in b:
            v = b[k]
            if k == 'maxTurns': v = max(1, min(500, int(v or 60)))
            elif k in ('jail', 'allowRemoteWrites'): v = bool(v)
            elif k == 'permissions': v = 'off' if v == 'off' else 'acceptEdits'
            else: v = str(v or '')
            CFG[k] = v
    if isinstance(b.get('publish'), dict):
        P = CFG.setdefault('publish', {})
        for k in ('domain', 'proxyDir', 'target', 'urlBase'):
            if k in b['publish']: P[k] = str(b['publish'][k] or '')
    if isinstance(b.get('auth'), dict):
        A = CFG.setdefault('auth', {})
        if b['auth'].get('user'): A['user'] = str(b['auth']['user'])[:40]
        if b['auth'].get('pass'): A['pass'] = str(b['auth']['pass'])[:200]
        if b['auth'].get('clear'): CFG['auth'] = {}
    write_atomic(os.path.join(HERE, 'config.json'), json.dumps(CFG, indent=2) + '\n')
    return config_view()

def status():
    def ver(cmd):
        try: return subprocess.run(cmd, capture_output=True, text=True, timeout=20).stdout.strip()[:60]
        except Exception: return ''
    pw = os.path.exists(os.path.join(CFG.get('playwrightRoot', os.path.expanduser('~')), 'node_modules', 'playwright'))
    return {'claude': ver(['claude', '--version']), 'node': ver(['node', '--version']), 'bwrap': os.path.exists('/usr/bin/bwrap'), 'playwright': pw, 'jail': bool(CFG.get('jail')), 'permissions': CFG.get('permissions', 'acceptEdits'), 'publishTarget': (CFG.get('publish') or {}).get('target', ''), 'templates': sorted(x for x in os.listdir(TEMPLATES) if os.path.isdir(os.path.join(TEMPLATES, x))), 'games': len([x for x in os.listdir(GAMES) if os.path.isdir(os.path.join(GAMES, x))])}

# ---- HTTP ----
class H(http.server.BaseHTTPRequestHandler):
    server_version = 'VoidLuxStudio/0.1'; protocol_version = 'HTTP/1.1'
    def log_message(self, *a): pass
    def send_json(self, obj, code=200):
        body = json.dumps(obj).encode(); self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Cache-Control', 'no-store'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
    def send_file(self, path):
        if not os.path.isfile(path): return self.send_json({'error': 'not found'}, 404)
        ext = os.path.splitext(path)[1].lower(); data = open(path, 'rb').read()
        self.send_response(200); self.send_header('Content-Type', MIME.get(ext, 'application/octet-stream')); self.send_header('Cache-Control', 'no-store'); self.send_header('Content-Length', str(len(data))); self.end_headers(); self.wfile.write(data)
    def body(self):
        n = int(self.headers.get('Content-Length') or 0)
        raw = self.rfile.read(n) if n else b''
        if not raw: return {}
        try: return json.loads(raw.decode('utf-8'))
        except ValueError: raise ValueError('the body is not JSON')
    # a request straight from this machine (no proxy header) is trusted: the soak runner and studio/bin/soak. Anything that
    # came through nginx (X-Forwarded-For set) or from the network must sign in with the Basic auth in config.json "auth"
    def direct(self): return self.client_address[0] in LOCAL and not self.headers.get('X-Forwarded-For')
    def authed(self):
        a = CFG.get('auth') or {}
        if not a.get('user'): return False
        h = self.headers.get('Authorization') or ''
        try: u, p = base64.b64decode(h.split(' ', 1)[1]).decode('utf-8').split(':', 1)
        except Exception: return False
        return hmac.compare_digest(u, str(a['user'])) and hmac.compare_digest(p, str(a.get('pass', '')))
    def local(self): return self.direct() or self.authed() or CFG.get('allowRemoteWrites')
    def gate(self, path):
        """True when the request may go on. The landing page and its assets are public; everything else needs a direct local
        caller or a signed-in one (when "auth" is configured; without it, only direct local callers get in from a proxy)."""
        if path == '/' or path.startswith('/public/') or path.endswith('/favicon.ico'): return True
        if self.direct() or self.authed(): return True
        if not (CFG.get('auth') or {}).get('user'):
            body = b'VoidLux Studio is reachable only from its own machine until "auth" (user, pass) is set in studio/config.json.'
            self.send_response(403); self.send_header('Content-Type', 'text/plain'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body); return False
        body = b'VoidLux Studio: sign in.'
        self.send_response(401); self.send_header('WWW-Authenticate', 'Basic realm="VoidLux Studio"'); self.send_header('Content-Type', 'text/plain'); self.send_header('Cache-Control', 'no-store'); self.send_header('Content-Length', str(len(body))); self.end_headers(); self.wfile.write(body)
        return False
    def safe(self, base, rel):
        p = os.path.realpath(os.path.join(base, urllib.parse.unquote(rel).lstrip('/')))
        if not (p == base or p.startswith(base + os.sep)): raise ValueError('path')
        if os.path.isdir(p): p = os.path.join(p, 'index.html')
        return p

    # https://<slug>.<domain>/ is a published game: its dist/ served at /, public, no sign-in (that is the product). The
    # domain comes from config publish.domain; nginx says which name the visitor asked for in X-Forwarded-Host
    def game_host(self):
        dom = (CFG.get('publish') or {}).get('domain') or ''
        host = (self.headers.get('X-Forwarded-Host') or self.headers.get('Host') or '').split(':')[0].lower()
        if not dom or not host.endswith('.' + dom): return None
        slug = host[:-len(dom) - 1]
        if not SLUG.match(slug) or slug == 'www' or not os.path.isdir(os.path.join(GAMES, slug, 'dist')): return None
        return slug
    def do_GET(self):
        u = urllib.parse.urlsplit(self.path); path = u.path; q = dict(urllib.parse.parse_qsl(u.query))
        slug = self.game_host()
        if slug:
            try: return self.send_file(self.safe(os.path.join(GAMES, slug, 'dist'), path) if not path.endswith('/favicon.ico') or os.path.exists(os.path.join(GAMES, slug, 'dist', 'favicon.ico')) else os.path.join(HERE, 'ui', 'favicon.svg'))
            except ValueError: return self.send_json({'error': 'not found'}, 404)
        m = re.match(r'^/g/([a-z0-9-]+)/(.*)$', path)  # a published game's files, public (see publish)
        if m and os.path.isdir(os.path.join(GAMES, m.group(1), 'dist')):
            try: return self.send_file(self.safe(os.path.join(GAMES, m.group(1), 'dist'), m.group(2)))
            except ValueError: return self.send_json({'error': 'not found'}, 404)
        if not self.gate(path): return
        try:
            if path == '/': return self.send_file(os.path.join(ROOT, 'public', 'index.html') if os.path.exists(os.path.join(ROOT, 'public', 'index.html')) else os.path.join(HERE, 'ui', 'index.html'))  # the VoidLux landing page; the studio is at /studio/
            if path.startswith('/public/'): return self.send_file(self.safe(os.path.join(ROOT, 'public'), path[8:]))
            if path in ('/studio', '/studio/'): return self.send_file(os.path.join(HERE, 'ui', 'index.html'))
            if path.endswith('/favicon.ico'): return self.send_file(os.path.join(HERE, 'ui', 'favicon.svg'))  # every page, games included: no 404 noise in soak reports
            m = re.match(r'^/game/([a-z0-9-]+)/?$', path)
            if m: gamedir(m.group(1)); return self.send_file(os.path.join(HERE, 'ui', 'game.html'))
            if path.startswith('/ui/'): return self.send_file(self.safe(os.path.join(HERE, 'ui'), path[4:]))
            if path.startswith('/kit/'): return self.send_file(self.safe(KIT, path[5:]))
            if path.startswith('/editor/'): return self.send_file(self.safe(os.path.join(KIT, 'editor'), path[8:]))
            if path.startswith('/games/'): return self.send_file(self.safe(GAMES, path[7:]))
            if path.startswith('/templates/'): return self.send_file(self.safe(TEMPLATES, path[11:]))
            if path.startswith('/centipede/'): return self.send_file(self.safe(os.path.join(ROOT, 'centipede'), path[11:]))
            if path in ('/studio/settings', '/studio/settings/'): return self.send_file(os.path.join(HERE, 'ui', 'settings.html'))
            if path == '/api/status': return self.send_json(status())
            if path == '/api/config': return self.send_json(config_view())
            if path == '/api/games': return self.send_json([meta(s) for s in sorted(os.listdir(GAMES)) if os.path.isdir(os.path.join(GAMES, s)) and SLUG.match(s)])
            m = re.match(r'^/api/games/([a-z0-9-]+)(/.*)?$', path)
            if m:
                slug, sub = m.group(1), m.group(2) or ''; d = gamedir(slug)
                if sub == '': return self.send_json(dict(meta(slug), gameMd=open(os.path.join(d, 'GAME.md'), encoding='utf-8').read() if os.path.exists(os.path.join(d, 'GAME.md')) else '', checkpointList=checkpoints(slug), soaks=list_soaks(slug), events=agent_for(slug).since(0)[-200:]))
                if sub == '/file':
                    p = self.safe(d, q.get('path', 'GAME.md'))
                    if not p.endswith(('.md', '.js', '.html', '.json', '.txt')): raise ValueError('text files only')
                    return self.send_json({'path': q.get('path'), 'text': open(p, encoding='utf-8').read()})
                if sub == '/events': return self.sse(agent_for(slug), int(q.get('since') or 0))
                if sub == '/sfx': return self.send_json(read_sfx(d))
                if sub == '/sounds': return self.send_json(sounds(d))
                if sub == '/tuning': return self.send_json(read_tuning(d))
                if sub == '/soaks': return self.send_json(list_soaks(slug))
                m2 = re.match(r'^/soak/([0-9-]+)\.(json|png)$', sub)
                if m2: return self.send_file(os.path.join(d, '.studio', 'soaks', m2.group(1) + '.' + m2.group(2)))
                if sub == '/checkpoints': return self.send_json(checkpoints(slug))
            self.send_json({'error': 'not found'}, 404)
        except FileNotFoundError as e: self.send_json({'error': str(e)}, 404)
        except ValueError as e: self.send_json({'error': str(e)}, 400)
        except Exception as e: self.send_json({'error': '%s: %s' % (type(e).__name__, e)}, 500)

    def sse(self, agent, since):
        self.send_response(200); self.send_header('Content-Type', 'text/event-stream'); self.send_header('Cache-Control', 'no-store'); self.send_header('X-Accel-Buffering', 'no'); self.end_headers()
        q = agent.listen()
        try:
            for ev in agent.since(since): self.wfile.write(('id: %d\ndata: %s\n\n' % (ev['id'], json.dumps(ev))).encode())
            self.wfile.write(('event: status\ndata: %s\n\n' % json.dumps(agent.status())).encode()); self.wfile.flush()
            while True:
                try: ev = q.get(timeout=15); self.wfile.write(('id: %d\ndata: %s\n\n' % (ev['id'], json.dumps(ev))).encode())
                except Exception as e:
                    if isinstance(e, (BrokenPipeError, ConnectionResetError)): break
                    self.wfile.write(b': keepalive\n\n')
                self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError): pass
        finally: agent.unlisten(q)

    def do_POST(self):
        u = urllib.parse.urlsplit(self.path); path = u.path; q = dict(urllib.parse.parse_qsl(u.query))
        if self.game_host(): return self.send_json({'error': 'a published game takes no writes'}, 405)
        if not self.gate(path): return
        if not self.local(): return self.send_json({'error': 'writes need a sign-in (config.json "auth") or a caller on this machine'}, 403)
        try:
            b = self.body()
            if path == '/api/config': return self.send_json(config_save(b))
            if path == '/api/games': return self.send_json(create_game(b.get('slug', ''), b.get('title', ''), b.get('brief', ''), b.get('template') or 'blank'), 201)
            if path == '/api/soak/report':
                slug = q.get('game', ''); d = gamedir(slug); os.makedirs(os.path.join(d, '.studio'), exist_ok=True)
                write_atomic(os.path.join(d, '.studio', 'last-page-report.json'), json.dumps(b, indent=1)); return self.send_json({'ok': True})
            m = re.match(r'^/api/games/([a-z0-9-]+)(/.*)$', path)
            if not m: return self.send_json({'error': 'not found'}, 404)
            slug, sub = m.group(1), m.group(2); d = gamedir(slug); a = agent_for(slug)
            if sub == '/agent':
                mode = b.get('mode', 'chat')
                if mode not in ('chat', 'pipeline', 'review', 'fix'): raise ValueError('mode is chat, pipeline, review or fix')
                prompt = (b.get('prompt') or '').strip()
                if mode == 'chat' and not prompt: raise ValueError('say what to do')
                try: a.start(mode, prompt, rounds=int(b.get('rounds', 2)))
                except RuntimeError as e: return self.send_json({'error': str(e)}, 409)
                return self.send_json(a.status(), 202)
            if sub == '/agent/stop': a.stop(); return self.send_json({'ok': True})
            if sub == '/agent/reset': a.state['session'] = None; a._save_state(); a.emit('note', text='next run starts a fresh session'); return self.send_json(a.status())
            if sub == '/checkpoint': return self.send_json({'tag': checkpoint(slug, b.get('label') or 'checkpoint'), 'checkpoints': checkpoints(slug)})
            if sub == '/rollback': return self.send_json({'checkpoints': rollback(slug, b.get('ref', ''))})
            if sub == '/soak':
                secs = max(5, min(1800, int(q.get('secs') or b.get('secs') or 60))); fast = max(1, min(30, int(q.get('fast') or b.get('fast') or 4)))
                if q.get('wait') or b.get('wait'): return self.send_json(run_soak(slug, secs, fast))
                threading.Thread(target=run_soak, args=(slug, secs, fast), daemon=True).start(); return self.send_json({'started': True}, 202)
            if sub == '/file':
                rel = b.get('path', 'GAME.md'); p = self.safe(d, rel)
                if not p.endswith(('.md', '.json', '.txt')) or '/js/' in p: raise ValueError('the dashboard edits docs; code is the agent\'s and the editors\'')
                write_atomic(p, str(b.get('text', ''))); return self.send_json({'ok': True})
            if sub == '/sfx': return self.send_json({'ok': True, 'sounds': save_sfx(d, b)})
            if sub == '/tuning': return self.send_json({'ok': True, 'count': save_tuning(d, b)})
            if sub == '/music/save': return self.send_json({'ok': True, 'action': save_song(d, b.get('name'), b.get('snippet')), 'file': 'js/music-themes.js'})
            if sub == '/music/delete': delete_song(d, b.get('name', '')); return self.send_json({'ok': True, 'file': 'js/music-themes.js'})
            if sub == '/publish': return self.send_json(publish(slug))
            if sub == '/meta':
                m = json.load(open(os.path.join(d, 'game.json')))
                for k in ('title', 'status', 'price', 'tags', 'notes'):
                    if k in b: m[k] = b[k]
                write_atomic(os.path.join(d, 'game.json'), json.dumps(m, indent=1) + '\n'); return self.send_json(meta(slug))
            self.send_json({'error': 'not found'}, 404)
        except FileNotFoundError as e: self.send_json({'error': str(e)}, 404)
        except ValueError as e: self.send_json({'error': str(e)}, 400)
        except Exception as e: self.send_json({'error': '%s: %s' % (type(e).__name__, e)}, 500)

class Server(http.server.ThreadingHTTPServer):
    daemon_threads = True; allow_reuse_address = True

if __name__ == '__main__':
    print('VoidLux Studio on http://localhost:%d/  (games in %s)' % (PORT, GAMES), flush=True)
    try: Server((CFG.get('host', '0.0.0.0'), PORT), H).serve_forever()
    except KeyboardInterrupt: pass
