<?php
/**
 * EmailConfirm: a member confirms their email before anything that costs runs for them (a builder run, an artist's job, a note
 * queued for the builder). A form-testing bot made an account on 2026-11-04; an account that cannot click a link in its mail
 * cannot spend.
 *
 * Confirmed: member.email_verified = 1 (an invite already sets it), or the member signs in with Google (Google confirmed it), or an
 * admin (level 50 or lower), or no member at all (the forge's own runs: queued notes, the sweep, playtests it starts).
 * The link: /verify/confirm?t=<token>, good for 2 days and once; only its hash is kept (member.confirm_hash). It opens a page whose
 * BUTTON confirms (a POST), because mail previews open links by themselves. A link can be asked for once a minute.
 */
namespace app;

use \Flight as Flight;

class EmailConfirm {
    public const DAYS = 2;
    public const EVERY = 60;

    public static function confirmed(object $m): bool {
        return (int) $m->level <= LEVELS['ADMIN'] || (int) $m->emailVerified === 1 || trim((string) $m->googleEid) !== '';
    }

    /** Refuses a paid run for a member who has not confirmed their email; nothing for the forge's own runs (member 0). */
    public static function need(int $memberId): void {
        if ($memberId <= 0) return;
        $m = Bean::load('member', $memberId); if (!$m->id) throw new \InvalidArgumentException("member {$memberId} does not exist");
        if (self::confirmed($m)) return;
        throw new \InvalidArgumentException("Confirm your email first: the builder and the artist work once you have. Open " . self::link() . ' and press Send me the link.');
    }

    /** Sends the member a fresh link. Throws when it may not yet (once a minute) or when the mail could not go. */
    public static function send(object $m): void {
        if (self::confirmed($m)) throw new \InvalidArgumentException('Your email is confirmed already.');
        $last = strtotime((string) $m->confirmSentAt) ?: 0; if (time() - $last < self::EVERY) throw new \InvalidArgumentException('A link went a moment ago: give it a minute, and look in your spam folder too.');
        if (!Mailer::isConfigured()) throw new \RuntimeException('Mail is not connected (Connections → Mailgun), so no link can be sent: an admin connects it.');
        $token = bin2hex(random_bytes(24));
        $m->confirmHash = hash('sha256', $token); $m->confirmExpires = date('Y-m-d H:i:s', time() + self::DAYS * 86400); $m->confirmSentAt = date('Y-m-d H:i:s'); Bean::store($m);
        $url = self::base() . '/verify/confirm?t=' . $token; $name = htmlspecialchars((string) ($m->displayName ?: $m->username), ENT_QUOTES);
        $html = "<p>Hello {$name},</p><p>Press the button on this page to confirm your email for VoidLux. Then the builder and the artist can work for you.</p>"
              . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">Confirm my email</a></p><p>The link works for ' . self::DAYS . ' days. If you did not sign up, you can ignore this.</p>';
        if (!Mailer::create()->to((string) $m->email, (string) ($m->displayName ?: $m->username))->subject('Confirm your email for VoidLux')->send($html))
            throw new \RuntimeException('The link could not be sent just now (the mail service refused it). Try again in a while.');
    }

    /** The member a link belongs to, while it is good; null when it is not (wrong, used or old). */
    public static function holder(string $token): ?object {
        if (!preg_match('/^[0-9a-f]{48}$/', $token)) return null;
        $m = Bean::findOne('member', 'confirm_hash = ?', [hash('sha256', $token)]); if (!$m) return null;
        return strtotime((string) $m->confirmExpires) > time() ? $m : null;
    }
    /** Confirms the email the link was sent to; the link is spent. */
    public static function confirm(string $token): object {
        $m = self::holder($token); if (!$m) throw new \InvalidArgumentException('That link is used, too old, or not ours. Sign in and ask for a new one.');
        $m->emailVerified = 1; $m->confirmHash = null; $m->confirmExpires = null; Bean::store($m); return $m;
    }

    public static function link(): string { return self::base() . '/verify'; }
    private static function base(): string { $b = rtrim((string) Flight::get('app.baseurl'), '/'); if ($b === '') throw new \RuntimeException('app.baseurl is not set (conf/config.ini [app] baseurl): the confirmation link needs it'); return $b; }
}
