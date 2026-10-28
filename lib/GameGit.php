<?php
/**
 * GameGit — a game linked to its own GitHub repository.
 *
 * One GitHub connection for the whole forge (Connections → GitHub REST API: a token of the account that owns the games'
 * repositories) is used only to create a repository and to add or remove a key on it. Each linked game then gets its
 * own SSH deploy key, which can write to that one repository and nothing else: the forge makes it, puts its public half
 * on the repository, and keeps its private half encrypted with the app's own key in secure/gamekeys/<slug>.enc. Publish
 * pushes with that key. So anyone who may publish the game in the forge pushes to its repository, whether or not they
 * have a GitHub account, and no game can reach another's repository or the server's own key.
 *
 * The private key never enters the game's folder (the jail where agents run shows only that folder); it is decrypted
 * into a 0600 file in a private temporary directory for one git command and removed. The link is recorded in
 * data/gitlinks/<slug>.json.
 */
namespace app;

class GameGit {
    public static ?string $remoteForTests = null; // a local bare repository instead of GitHub (tests: no token, no key, no network)

    private static function linkFile(string $slug): string { $d = Paths::root() . '/data/gitlinks'; if (!is_dir($d)) mkdir($d, 0775, true); return "$d/$slug.json"; }
    private static function keyFile(string $slug): string { $d = Paths::root() . '/secure/gamekeys'; if (!is_dir($d)) mkdir($d, 0700, true); return "$d/$slug.enc"; }
    public static function link(string $slug): ?array { $f = self::linkFile($slug); return is_file($f) ? (json_decode((string) file_get_contents($f), true) ?: null) : null; }

    // ---- the forge's GitHub connection ----
    public static function token(): string {
        // GitHub (OAuth): signed in with GitHub, preferred; GitHub REST API: a pasted token
        $conn = ConnectionStore::for('github_oauth') ?? ConnectionStore::for('github_api');
        if (!$conn) throw new \RuntimeException('No GitHub connection: an admin signs the forge in to GitHub at /forge/github (or pastes a token under Connections → GitHub REST API).');
        $t = ConnectionStore::ownToken($conn); if ($t === '') throw new \RuntimeException('The GitHub connection has no usable token (empty, or it will not decrypt with this install\'s key): connect it again under Connections.');
        return $t;
    }
    public static function api(string $method, string $path, ?array $body = null): array {
        $ch = curl_init('https://api.github.com' . $path);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . self::token(), 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: voidlux-forge', 'Content-Type: application/json']]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        $raw = (string) curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($code === 0) throw new \RuntimeException("GitHub did not answer: $err");
        $j = json_decode($raw, true) ?: [];
        if ($code >= 400) throw new \RuntimeException("GitHub said $code to $method $path: " . (string) ($j['message'] ?? mb_substr($raw, 0, 200)) . (!empty($j['errors']) ? ' (' . json_encode($j['errors']) . ')' : ''));
        return $j + ['_status' => $code];
    }
    /** Who the connection is: the default owner of new repositories. */
    public static function account(): string { return (string) (self::api('GET', '/user')['login'] ?? ''); }

    // ---- sign in with GitHub (the forge's own OAuth flow: this runtime has no /connections/callback) ----
    private static function appFile(): string { return Paths::root() . '/secure/github-oauth-app.enc'; }
    /** The forge's GitHub OAuth App (registered by an admin on GitHub): client id and secret, encrypted with the app's key. */
    public static function oauthApp(): ?array { $f = self::appFile(); if (!is_file($f)) return null;
        try { $j = json_decode(EncryptionService::decryptWith((string) file_get_contents($f), ConnectionStore::ownKey()), true); } catch (\Throwable $e) { throw new \RuntimeException('the saved GitHub OAuth App will not decrypt with this install\'s key: save it again'); }
        return is_array($j) && !empty($j['client_id']) ? $j : null; }
    public static function saveOauthApp(string $id, string $secret): void {
        if (!preg_match('/^[A-Za-z0-9._-]{8,80}$/', $id)) throw new \InvalidArgumentException('that does not look like a GitHub client id');
        if (strlen($secret) < 20) throw new \InvalidArgumentException('that does not look like a GitHub client secret');
        file_put_contents(self::appFile(), EncryptionService::encryptWith(json_encode(['client_id' => $id, 'client_secret' => $secret]), ConnectionStore::ownKey())); chmod(self::appFile(), 0600); }
    public static function callbackUrl(): string { return GameStore::baseUrl() . '/forge/githubcallback'; }
    public static function authorizeUrl(string $state): string { $a = self::oauthApp(); if (!$a) throw new \RuntimeException('no GitHub OAuth App saved yet');
        return 'https://github.com/login/oauth/authorize?' . http_build_query(['client_id' => $a['client_id'], 'redirect_uri' => self::callbackUrl(), 'scope' => 'read:user repo', 'state' => $state, 'allow_signup' => 'false']); }
    /** The code GitHub sent back, for a token; stored as this install's github_oauth connection. */
    public static function finishSignIn(string $code): array {
        $a = self::oauthApp(); if (!$a) throw new \RuntimeException('no GitHub OAuth App saved');
        $ch = curl_init('https://github.com/login/oauth/access_token'); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: voidlux-forge'], CURLOPT_POSTFIELDS => http_build_query(['client_id' => $a['client_id'], 'client_secret' => $a['client_secret'], 'code' => $code, 'redirect_uri' => self::callbackUrl()])]);
        $raw = (string) curl_exec($ch); curl_close($ch); $j = json_decode($raw, true) ?: [];
        if (empty($j['access_token'])) throw new \RuntimeException('GitHub did not give a token: ' . (string) ($j['error_description'] ?? $j['error'] ?? mb_substr($raw, 0, 200)));
        $u = curl_init('https://api.github.com/user'); curl_setopt_array($u, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $j['access_token'], 'Accept: application/vnd.github+json', 'User-Agent: voidlux-forge']]); $me = json_decode((string) curl_exec($u), true) ?: []; curl_close($u);
        if (empty($me['login'])) throw new \RuntimeException('signed in, but GitHub would not say who you are');
        ConnectionStore::put('github_oauth', 'production', ['external_eid' => (string) $me['id'], 'external_name' => (string) $me['login'], 'external_url' => 'https://github.com/' . $me['login'], 'token_type' => (string) ($j['token_type'] ?? 'bearer'), 'scopes' => (string) ($j['scope'] ?? ''), 'auth_type' => 'oauth', 'access_token' => (string) $j['access_token']]);
        return ['login' => (string) $me['login'], 'scopes' => (string) ($j['scope'] ?? '')];
    }

    // ---- linking ----
    /** Link a game to owner/name (made first when $create): a deploy key made for it, the game's origin pointed at it, then a first push. */
    public static function linkGame(string $slug, string $repo, bool $create, bool $private, int $memberId): array {
        if (!preg_match('/^[A-Za-z0-9_.-]{1,39}\/[A-Za-z0-9_.-]{1,100}$/', $repo)) throw new \InvalidArgumentException('the repository is owner/name, e.g. mfrederico/lonelycat');
        $d = GameStore::dir($slug); if (self::link($slug)) throw new \InvalidArgumentException('this game is linked already: unlink it first');
        [$owner, $name] = explode('/', $repo, 2); $keyId = null; $url = '';
        if (self::$remoteForTests === null) {
            if ($create) { $me = self::account(); $body = ['name' => $name, 'private' => $private, 'auto_init' => false, 'description' => 'A VoidLux forge game: ' . (string) (GameStore::row($slug)->title ?? $slug)];
                self::api('POST', strcasecmp($owner, $me) === 0 ? '/user/repos' : '/orgs/' . rawurlencode($owner) . '/repos', $body); }
            else self::api('GET', '/repos/' . $repo); // it exists and the token can see it
            [$pub, $priv] = self::makeKey($slug);
            $k = self::api('POST', "/repos/$repo/keys", ['title' => "voidlux forge: $slug", 'key' => $pub, 'read_only' => false]); $keyId = (int) ($k['id'] ?? 0);
            file_put_contents(self::keyFile($slug), EncryptionService::encryptWith($priv, ConnectionStore::ownKey())); chmod(self::keyFile($slug), 0600);
            $url = "git@github.com:$repo.git";
        } else $url = self::$remoteForTests;
        [, $has] = GameStore::git($d, ['remote']); GameStore::git($d, in_array('origin', array_map('trim', explode("\n", $has)), true) ? ['remote', 'set-url', 'origin', $url] : ['remote', 'add', 'origin', $url]);
        $link = ['repo' => $repo, 'url' => $url, 'key_id' => $keyId, 'private' => $private, 'linked_at' => date('c'), 'linked_by' => GameStore::memberName($memberId)];
        file_put_contents(self::linkFile($slug), json_encode($link, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        GameStore::emit($slug, 'note', ['text' => "linked to GitHub: $repo" . ($create ? ' (made just now)' : '')]);
        $p = self::push($slug); return self::link($slug) + ['push' => $p];
    }
    public static function unlinkGame(string $slug): array {
        $l = self::link($slug); if (!$l) throw new \InvalidArgumentException('this game is not linked'); $note = '';
        if (self::$remoteForTests === null && !empty($l['key_id'])) { try { self::api('DELETE', "/repos/{$l['repo']}/keys/{$l['key_id']}"); } catch (\Throwable $e) { $note = 'the key could not be removed from GitHub (remove it by hand under the repository\'s Deploy keys): ' . $e->getMessage(); } }
        @unlink(self::keyFile($slug)); @unlink(self::linkFile($slug)); GameStore::git(GameStore::dir($slug), ['remote', 'remove', 'origin']);
        GameStore::emit($slug, 'note', ['text' => "unlinked from GitHub ({$l['repo']}); the repository itself is left as it is" . ($note ? "; $note" : '')]);
        return ['repo' => $l['repo'], 'note' => $note];
    }
    /** An ed25519 key pair made for this game: [public line, private PEM]. */
    private static function makeKey(string $slug): array {
        $t = sys_get_temp_dir() . '/vl-key-' . bin2hex(random_bytes(6)); mkdir($t, 0700);
        exec('ssh-keygen -q -t ed25519 -N "" -C ' . escapeshellarg("voidlux-forge:$slug") . ' -f ' . escapeshellarg("$t/k") . ' 2>&1', $o, $rc);
        $pub = trim((string) @file_get_contents("$t/k.pub")); $priv = (string) @file_get_contents("$t/k"); @unlink("$t/k"); @unlink("$t/k.pub"); @rmdir($t);
        if ($rc !== 0 || $pub === '' || $priv === '') throw new \RuntimeException('could not make a deploy key: ' . implode(' ', $o));
        return [$pub, $priv];
    }

    // ---- pushing with the game's own key ----
    public static function push(string $slug): array {
        $l = self::link($slug); if (!$l) return ['ok' => false, 'text' => 'not linked'];
        $d = GameStore::dir($slug); [, $br] = GameStore::git($d, ['rev-parse', '--abbrev-ref', 'HEAD']); $br = trim($br) ?: 'main';
        $env = ''; $t = null;
        if (self::$remoteForTests === null) {
            $enc = (string) @file_get_contents(self::keyFile($slug)); if ($enc === '') throw new \RuntimeException("the game's deploy key is missing (secure/gamekeys/$slug.enc): unlink the game and link it again");
            try { $priv = EncryptionService::decryptWith($enc, ConnectionStore::ownKey()); } catch (\Throwable $e) { throw new \RuntimeException("the game's deploy key will not decrypt with this install's key: unlink and link it again"); }
            $t = sys_get_temp_dir() . '/vl-push-' . bin2hex(random_bytes(6)); mkdir($t, 0700); file_put_contents("$t/k", $priv); chmod("$t/k", 0600);
            $kh = Paths::root() . '/data/gitlinks/known_hosts';
            $env = 'GIT_SSH_COMMAND=' . escapeshellarg('ssh -i ' . escapeshellarg("$t/k") . ' -o IdentitiesOnly=yes -o IdentityAgent=none -o BatchMode=yes -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=' . escapeshellarg($kh)) . ' ';
        }
        exec($env . 'git -C ' . escapeshellarg($d) . ' push -q origin ' . escapeshellarg($br) . ' 2>&1', $o, $rc);
        if ($t) { @unlink("$t/k"); @rmdir($t); }
        $text = $rc === 0 ? "pushed {$br} to {$l['repo']}" : 'push to ' . $l['repo'] . ' failed: ' . mb_substr(trim(implode(' ', $o)), -400);
        $l['last_push'] = date('c'); $l['last_push_ok'] = $rc === 0; $l['last_push_text'] = $text; file_put_contents(self::linkFile($slug), json_encode($l, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        GameStore::emit($slug, $rc === 0 ? 'note' : 'error', ['text' => 'GitHub: ' . $text]);
        return ['ok' => $rc === 0, 'text' => $text];
    }
}
